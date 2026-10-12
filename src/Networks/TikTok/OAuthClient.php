<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\TikTok;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Throwable;

/** Everything that talks to TikTok's Login Kit (OAuth 2.0 v2) and user info endpoints. */
final class OAuthClient
{
    private const AUTHORIZE = 'https://www.tiktok.com/v2/auth/authorize/';

    private const TOKEN = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const REVOKE = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    private const USER = 'https://open.tiktokapis.com/v2/user/info/';

    /** Read the account and post to it directly. */
    private const DEFAULT_SCOPES = ['user.info.basic', 'video.publish'];

    /** Sent on both the authorization request and the token exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = $this->config('redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route(config('social-autopost.routes.name', 'social-autopost.').'callback', ['network' => TikTokNetwork::KEY]);
    }

    /** @throws NotConfigured */
    public function authorizationUrl(string $state): string
    {
        [$clientKey] = $this->credentials();

        return self::AUTHORIZE.'?'.http_build_query([
            'client_key' => $clientKey,
            'response_type' => 'code',
            'scope' => implode(',', $this->requestedScopes()),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @throws RequestFailed|NotConfigured */
    public function exchange(string $code): TikTokAccount
    {
        [$clientKey, $clientSecret] = $this->credentials();

        try {
            $response = $this->http()->asForm()->post(self::TOKEN, [
                'client_key' => $clientKey,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ]);
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach TikTok: '.$e->getMessage(), 0);
        }

        $tokens = self::tokens($response) ?? throw (TikTokFailure::failed($response)
            ? TikTokFailure::from($response)
            : new RequestFailed('TikTok returned no access token.', $response->status()));

        $openId = $response->json('open_id');

        if (! is_string($openId) || $openId === '') {
            throw new RequestFailed('TikTok did not return the account id.');
        }

        $profile = $this->profile($tokens['access_token']);
        $user = $profile['user'];

        return new TikTokAccount(
            accessToken: $tokens['access_token'],
            openId: $openId,
            refreshToken: $tokens['refresh_token'],
            name: self::string($user['display_name'] ?? null),
            picture: self::string($user['avatar_url'] ?? null),
            scopes: self::scopes($response->json('scope')),
            expiresAt: $tokens['expires_at'],
            refreshExpiresAt: $tokens['refresh_expires_at'],
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Trade a refresh token for a new pair. TikTok may return a new refresh
     * token, which the caller must store. Null when TikTok refuses.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?CarbonImmutable, refresh_expires_at: ?CarbonImmutable}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        try {
            [$clientKey, $clientSecret] = $this->credentials();

            $response = $this->http()->asForm()->post(self::TOKEN, [
                'client_key' => $clientKey,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
        } catch (Throwable) {
            return null;
        }

        return self::tokens($response);
    }

    /**
     * The account behind a token. Status is "active", "expired" (TikTok refused
     * the token) or "unknown" (TikTok could not be asked).
     *
     * @return array{status: 'active'|'expired'|'unknown', user: array<string, mixed>}
     */
    public function profile(string $accessToken): array
    {
        try {
            $response = $this->http()->withToken($accessToken)->get(self::USER, [
                'fields' => 'open_id,avatar_url,display_name',
            ]);
        } catch (Throwable) {
            return ['status' => 'unknown', 'user' => []];
        }

        if (TikTokFailure::failed($response)) {
            return ['status' => TikTokFailure::from($response)->isUnauthorized() ? 'expired' : 'unknown', 'user' => []];
        }

        $user = $response->json('data.user');

        return ['status' => 'active', 'user' => is_array($user) ? $user : []];
    }

    /** Revoke the account's tokens at TikTok. False when TikTok refuses or cannot be asked. */
    public function revoke(string $accessToken): bool
    {
        try {
            [$clientKey, $clientSecret] = $this->credentials();

            $response = $this->http()->asForm()->post(self::REVOKE, [
                'client_key' => $clientKey,
                'client_secret' => $clientSecret,
                'token' => $accessToken,
            ]);
        } catch (Throwable) {
            return false;
        }

        return ! TikTokFailure::failed($response);
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
     * The tokens and their expiries from a token response, or null when it has no access token.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?CarbonImmutable, refresh_expires_at: ?CarbonImmutable}|null
     */
    private static function tokens(Response $response): ?array
    {
        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $refreshToken = $response->json('refresh_token');
        $in = fn (string $key): ?CarbonImmutable => is_numeric($seconds = $response->json($key)) && (int) $seconds > 0
            ? CarbonImmutable::now()->addSeconds((int) $seconds)
            : null;

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_at' => $in('expires_in'),
            'refresh_expires_at' => $in('refresh_expires_in'),
        ];
    }

    /** @return list<string> */
    private function requestedScopes(): array
    {
        $configured = $this->config('scopes');

        return is_array($configured) && $configured !== []
            ? array_values(array_filter($configured, 'is_string'))
            : self::DEFAULT_SCOPES;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws NotConfigured
     */
    private function credentials(): array
    {
        $key = $this->config('client_key');
        $secret = $this->config('client_secret');

        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            throw new NotConfigured('TikTok is not configured: add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to .env.');
        }

        return [$key, $secret];
    }

    private function config(string $key): mixed
    {
        return config('social-autopost.networks.'.TikTokNetwork::KEY.'.'.$key);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social-autopost.http.timeout', 20))->acceptJson();
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
