<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Instagram;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Throwable;

/**
 * Everything that talks to Instagram (the Instagram API with Instagram Login):
 * the authorization window, the token exchange and refresh, the profile, and
 * publishing through a media container. InstagramNetwork is the only caller.
 */
final class InstagramClient
{
    private const AUTHORIZE = 'https://www.instagram.com/oauth/authorize';

    private const TOKEN = 'https://api.instagram.com/oauth/access_token';

    private const GRAPH = 'https://graph.instagram.com';

    /** Read the account and publish to it. */
    private const DEFAULT_SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

    /** Graph API error code for an invalid, expired or revoked access token. */
    private const INVALID_TOKEN = 190;

    /** Instagram cuts captions at 2,200 characters. */
    public const CAPTION_LIMIT = 2200;

    /** Sent on both the authorization request and the token exchange, so they always match. */
    public function redirectUri(): string
    {
        return $this->setting('redirect_uri') ?? route(config('social-autopost.routes.name', 'social-autopost.').'callback', [
            'network' => InstagramNetwork::KEY,
        ]);
    }

    /** @throws NotConfigured */
    public function authorizationUrl(string $state): string
    {
        [$clientId] = $this->credentials();

        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', $this->requestedScopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code for a short-lived token, trade it for a long-lived one
     * (60 days), and read the account it belongs to.
     *
     * @throws RequestFailed|NotConfigured
     */
    public function exchange(string $code): InstagramAccount
    {
        [$clientId, $clientSecret] = $this->credentials();

        $short = $this->send(fn () => $this->http()->asForm()->post(self::TOKEN, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]));

        // Instagram has answered both flat and wrapped in "data"; accept either.
        $shortToken = $short->json('access_token') ?? $short->json('data.0.access_token');

        if (! is_string($shortToken) || $shortToken === '') {
            throw new RequestFailed('Instagram returned no access token.', $short->status());
        }

        $long = $this->send(fn () => $this->http()->get(self::GRAPH.'/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $clientSecret,
            'access_token' => $shortToken,
        ]));

        $token = $long->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RequestFailed('Instagram returned no long-lived token.', $long->status());
        }

        $profile = $this->profile($token);

        if ($profile['status'] !== 'active') {
            throw new RequestFailed('Instagram did not return the account for the new token.');
        }

        $user = $profile['user'];
        $userId = self::id($user['user_id'] ?? null) ?? self::id($short->json('user_id') ?? $short->json('data.0.user_id'));

        if ($userId === null) {
            throw new RequestFailed('Instagram did not return the account id.');
        }

        return new InstagramAccount(
            accessToken: $token,
            userId: $userId,
            username: self::string($user['username'] ?? null),
            name: self::string($user['name'] ?? null),
            picture: self::string($user['profile_picture_url'] ?? null),
            expiresAt: self::expiry($long->json('expires_in')),
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Extend a long-lived token by another 60 days. The token must be at least
     * a day old and not yet expired. Null when Instagram refuses.
     *
     * @return array{access_token: string, expires_at: ?CarbonImmutable}|null
     */
    public function refresh(string $token): ?array
    {
        try {
            $response = $this->http()->get(self::GRAPH.'/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $token,
            ]);
        } catch (Throwable) {
            return null;
        }

        $fresh = $response->json('access_token');

        if (! $response->successful() || ! is_string($fresh) || $fresh === '') {
            return null;
        }

        return ['access_token' => $fresh, 'expires_at' => self::expiry($response->json('expires_in'))];
    }

    /**
     * The account behind a token. Status is "active", "expired" (Instagram
     * refused the token) or "unknown" (Instagram could not be asked).
     *
     * @return array{status: 'active'|'expired'|'unknown', user: array<string, mixed>}
     */
    public function profile(string $token): array
    {
        try {
            $response = $this->graph()->withToken($token)->get('me', [
                'fields' => 'user_id,username,name,profile_picture_url',
            ]);
        } catch (Throwable) {
            return ['status' => 'unknown', 'user' => []];
        }

        if ($response->failed()) {
            return ['status' => $this->failure($response)->isUnauthorized() ? 'expired' : 'unknown', 'user' => []];
        }

        $user = $response->json();

        return ['status' => 'active', 'user' => is_array($user) ? $user : []];
    }

    /**
     * Publish the post's video as a Reel, or else its image, with the commentary
     * as the caption: create a media container, wait until Instagram has
     * processed it, then publish it.
     *
     * @return string|null The media id; null when Instagram accepted the post without returning its id.
     *
     * @throws RequestFailed
     */
    public function publish(string $token, string $userId, LinkPost $post): ?string
    {
        $caption = mb_substr($post->commentaryText(), 0, self::CAPTION_LIMIT);
        $media = $post->videoUrl() !== null
            ? ['media_type' => 'REELS', 'video_url' => $post->videoUrl()]
            : ['image_url' => $post->imageUrl()];

        $container = $this->send(fn () => $this->graph()->withToken($token)->post("{$userId}/media", $media + ['caption' => $caption]))->json('id');

        if (! is_string($container) && ! is_int($container)) {
            throw new RequestFailed('Instagram returned no media container.');
        }

        $this->waitUntilReady($token, (string) $container);

        $response = $this->send(fn () => $this->graph()->withToken($token)->post("{$userId}/media_publish", [
            'creation_id' => (string) $container,
        ]));

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        return self::id($response->json('id'));
    }

    /**
     * Poll the container until Instagram has downloaded and processed the media.
     * Gives up after `processing_checks` checks, `processing_interval` seconds apart.
     *
     * @throws RequestFailed
     */
    private function waitUntilReady(string $token, string $container): void
    {
        $checks = max(1, (int) config('social-autopost.networks.'.InstagramNetwork::KEY.'.processing_checks', 10));
        $interval = max(0, (int) config('social-autopost.networks.'.InstagramNetwork::KEY.'.processing_interval', 5));

        for ($check = 1; $check <= $checks; $check++) {
            $response = $this->send(fn () => $this->graph()->withToken($token)->get($container, ['fields' => 'status_code,status']));
            $status = $response->json('status_code');

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                $reason = self::string($response->json('status')) ?? $status;

                // The media itself was refused: sending it again will not help.
                throw new RequestFailed('Instagram could not process the media: '.$reason, 422);
            }

            if ($check < $checks) {
                Sleep::for($interval)->seconds();
            }
        }

        // Status 0: retryable. An unpublished container is never posted, so a retry cannot post twice.
        throw new RequestFailed('Instagram is still processing the media. It will be tried again.', 0);
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws RequestFailed
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach Instagram: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $response;
    }

    /**
     * Instagram's error message, with an invalid token reported as 401 so the
     * driver marks the connection expired. The Graph API nests the error under
     * "error"; the token endpoint answers with error_message at the top level.
     */
    private function failure(Response $response): RequestFailed
    {
        $error = $response->json('error');
        $reason = is_array($error) ? ($error['message'] ?? null) : ($response->json('error_message') ?? $error);
        $code = is_array($error) ? ($error['code'] ?? null) : $response->json('code');

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();
        $status = $code === self::INVALID_TOKEN ? 401 : $response->status();

        return new RequestFailed('Instagram rejected the request: '.$reason, $status);
    }

    /** @return list<string> */
    private function requestedScopes(): array
    {
        $configured = config('social-autopost.networks.'.InstagramNetwork::KEY.'.scopes');

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
        $id = $this->setting('client_id');
        $secret = $this->setting('client_secret');

        if ($id === null || $secret === null) {
            throw new NotConfigured;
        }

        return [$id, $secret];
    }

    private function setting(string $key): ?string
    {
        $value = config('social-autopost.networks.'.InstagramNetwork::KEY.'.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social-autopost.http.timeout', 20))->acceptJson();
    }

    private function graph(): PendingRequest
    {
        return $this->http()->baseUrl(self::GRAPH.'/'.($this->setting('api_version') ?? 'v25.0'));
    }

    private static function expiry(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }

    /** Instagram ids are numeric strings, sometimes sent as JSON numbers. */
    private static function id(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') || is_int($value) ? (string) $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
