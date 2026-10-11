<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Throwable;

/** Everything that talks to X's OAuth 2.0 and account endpoints. */
final class OAuthClient
{
    private const AUTHORIZE = 'https://x.com/i/oauth2/authorize';

    private const TOKEN = 'https://api.x.com/2/oauth2/token';

    private const REVOKE = 'https://api.x.com/2/oauth2/revoke';

    private const ME = 'https://api.x.com/2/users/me';

    /** Post, read the account, and keep the connection alive with a refresh token. */
    private const DEFAULT_SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'offline.access'];

    /** Sent on both the authorization request and the token exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = $this->config('redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route(config('social-autopost.routes.name', 'social-autopost.').'callback', ['network' => 'x']);
    }

    /** A random PKCE verifier (43–128 unreserved characters). Keep it in the session until the callback. */
    public static function newCodeVerifier(): string
    {
        return Str::random(64);
    }

    /** The S256 challenge sent with the authorization request. */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @throws NotConfigured */
    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', $this->requestedScopes()),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @throws RequestFailed|NotConfigured */
    public function exchange(string $code, string $codeVerifier): XAccount
    {
        $token = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);

        if ($token->failed()) {
            throw XFailure::from($token);
        }

        $accessToken = $token->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RequestFailed('X returned no access token.', $token->status());
        }

        $refreshToken = $token->json('refresh_token');
        $account = $this->profile($accessToken);

        if ($account['status'] !== 'active') {
            throw new RequestFailed('X did not return the account for the new token.');
        }

        return new XAccount(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            username: self::stringOrNull($account['user']['username'] ?? null),
            name: self::stringOrNull($account['user']['name'] ?? null),
            picture: self::stringOrNull($account['user']['profile_image_url'] ?? null),
            scopes: self::scopes($token->json('scope')),
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Trade a refresh token for a new pair. X rotates the refresh token, so the
     * caller must store the returned one. Null when X refuses.
     *
     * @return array{access_token: string, refresh_token: ?string}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        try {
            $response = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        } catch (Throwable) {
            return null;
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $newRefresh = $response->json('refresh_token');

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : null,
        ];
    }

    /**
     * The account behind a token. Status is "active", "expired" (X answered 401)
     * or "unknown" (X could not be asked).
     *
     * @return array{status: 'active'|'expired'|'unknown', user: array<string, mixed>}
     */
    public function profile(string $accessToken): array
    {
        try {
            $response = $this->http()->withToken($accessToken)->acceptJson()->get(self::ME, [
                'user.fields' => 'profile_image_url,username',
            ]);
        } catch (Throwable) {
            return ['status' => 'unknown', 'user' => []];
        }

        if ($response->unauthorized()) {
            return ['status' => 'expired', 'user' => []];
        }

        if (! $response->successful()) {
            return ['status' => 'unknown', 'user' => []];
        }

        $user = $response->json('data');

        return ['status' => 'active', 'user' => is_array($user) ? $user : []];
    }

    /** Revoke the access token at X. False when X refuses or cannot be asked. */
    public function revoke(string $token): bool
    {
        try {
            return $this->tokenRequest(
                ['token' => $token, 'token_type_hint' => 'access_token'],
                self::REVOKE,
            )->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public static function scopes(mixed $scope): array
    {
        if (! is_string($scope) || $scope === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []));
    }

    /**
     * POST to an OAuth endpoint. A confidential app authenticates with HTTP
     * Basic; a public app (PKCE only, no secret) sends its client id in the body.
     *
     * @param  array<string, string>  $body
     *
     * @throws NotConfigured
     */
    private function tokenRequest(array $body, string $url = self::TOKEN): Response
    {
        $clientId = $this->clientId();
        $secret = $this->clientSecret();
        $request = $this->http()->asForm();

        if ($secret !== null) {
            $request = $request->withBasicAuth($clientId, $secret);
        } else {
            $body['client_id'] = $clientId;
        }

        return $request->post($url, $body);
    }

    /** @return list<string> */
    private function requestedScopes(): array
    {
        $configured = $this->config('scopes');

        return is_array($configured) && $configured !== []
            ? array_values(array_filter($configured, 'is_string'))
            : self::DEFAULT_SCOPES;
    }

    /** @throws NotConfigured */
    private function clientId(): string
    {
        $id = $this->config('client_id');

        if (! is_string($id) || $id === '') {
            throw new NotConfigured;
        }

        return $id;
    }

    private function clientSecret(): ?string
    {
        $secret = $this->config('client_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function config(string $key): mixed
    {
        return config('social-autopost.networks.x.'.$key);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social-autopost.http.timeout', 20));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
