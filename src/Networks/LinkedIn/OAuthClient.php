<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Throwable;

/** Everything that talks to LinkedIn's OAuth and identity endpoints. */
final class OAuthClient
{
    private const AUTHORIZE = 'https://www.linkedin.com/oauth/v2/authorization';

    private const TOKEN = 'https://www.linkedin.com/oauth/v2/accessToken';

    private const INTROSPECT = 'https://www.linkedin.com/oauth/v2/introspectToken';

    private const REVOKE = 'https://www.linkedin.com/oauth/v2/revoke';

    private const USERINFO = 'https://api.linkedin.com/v2/userinfo';

    /** Sent on both the authorization request and the token exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = config('social-autopost.networks.linkedin.redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route(config('social-autopost.routes.name', 'social-autopost.').'callback', ['network' => 'linkedin']);
    }

    public function authorizationUrl(string $state): string
    {
        [$clientId] = $this->credentials();

        return self::AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', (array) config('social-autopost.networks.linkedin.scopes', [])),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code and read the member's profile.
     *
     * @return array<string, mixed> The stored connection: access_token, author_urn, name, email, picture, scopes, expires_at, connected_at.
     *
     * @throws RequestFailed|NotConfigured
     */
    public function exchange(string $code): array
    {
        [$clientId, $clientSecret] = $this->credentials();

        $token = $this->http()->asForm()->post(self::TOKEN, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($token->failed()) {
            throw Failure::fromResponse($token);
        }

        $accessToken = $token->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RequestFailed('LinkedIn returned no access token.', $token->status());
        }

        $profile = $this->http()->withToken($accessToken)->acceptJson()->get(self::USERINFO);

        if ($profile->failed()) {
            throw Failure::fromResponse($profile);
        }

        $sub = $profile->json('sub');

        if (! is_string($sub) || $sub === '') {
            throw new RequestFailed('LinkedIn returned no member id (sub); is "Sign In with LinkedIn using OpenID Connect" added to the app?');
        }

        $now = CarbonImmutable::now();
        $expiresIn = $token->json('expires_in');

        return [
            'access_token' => $accessToken,
            'author_urn' => 'urn:li:person:'.$sub,
            'name' => self::stringOrNull($profile->json('name')),
            'email' => self::stringOrNull($profile->json('email')),
            'picture' => self::stringOrNull($profile->json('picture')),
            'scopes' => self::scopes($token->json('scope')),
            'expires_at' => is_numeric($expiresIn) ? $now->addSeconds((int) $expiresIn)->toIso8601String() : null,
            'connected_at' => $now->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null Null when LinkedIn cannot answer. */
    public function introspect(string $token): ?array
    {
        [$clientId, $clientSecret] = $this->credentials();

        return $this->jsonOrNull(fn () => $this->http()->asForm()->post(self::INTROSPECT, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'token' => $token,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function userinfo(string $token): ?array
    {
        return $this->jsonOrNull(fn () => $this->http()->withToken($token)->acceptJson()->get(self::USERINFO));
    }

    /** Best effort: false when LinkedIn refuses or cannot be reached. */
    public function revoke(string $token): bool
    {
        [$clientId, $clientSecret] = $this->credentials();

        try {
            return $this->http()->asForm()->post(self::REVOKE, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $token,
            ])->successful();
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

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $id = config('social-autopost.networks.linkedin.client_id');
        $secret = config('social-autopost.networks.linkedin.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new NotConfigured;
        }

        return [$id, $secret];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social-autopost.http.timeout', 20));
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>|null
     */
    private function jsonOrNull(callable $request): ?array
    {
        try {
            $response = $request();
        } catch (Throwable) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
