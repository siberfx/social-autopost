<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Bluesky;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Support\MediaDownload;
use Throwable;

/**
 * Everything that talks to the Bluesky service (AT Protocol XRPC): creating
 * and refreshing the session from the configured app password, the profile,
 * uploading the card image and creating posts. BlueskyNetwork is the only caller.
 */
final class BlueskyClient
{
    /**
     * Sign in with the configured identifier and app password and read the profile.
     *
     * @throws RequestFailed|NotConfigured
     */
    public function createSession(): BlueskyAccount
    {
        [$identifier, $password] = $this->credentials();

        $response = $this->send(fn () => $this->http()->post($this->url('com.atproto.server.createSession'), [
            'identifier' => $identifier,
            'password' => $password,
        ]));

        $accessJwt = self::string($response->json('accessJwt'));
        $refreshJwt = self::string($response->json('refreshJwt'));
        $did = self::string($response->json('did'));

        if ($accessJwt === null || $refreshJwt === null || $did === null) {
            throw new RequestFailed('Bluesky returned no session.', $response->status());
        }

        $profile = $this->profile($accessJwt, $did)['profile'];

        return new BlueskyAccount(
            accessJwt: $accessJwt,
            refreshJwt: $refreshJwt,
            did: $did,
            handle: self::string($response->json('handle')) ?? self::string($profile['handle'] ?? null),
            name: self::string($profile['displayName'] ?? null),
            picture: self::string($profile['avatar'] ?? null),
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Trade the refresh token for a new pair. Null when Bluesky refuses; the
     * caller then signs in again with the app password.
     *
     * @return array{access_jwt: string, refresh_jwt: string}|null
     */
    public function refreshSession(string $refreshJwt): ?array
    {
        try {
            $response = $this->http()->withToken($refreshJwt)->post($this->url('com.atproto.server.refreshSession'));
        } catch (Throwable) {
            return null;
        }

        $accessJwt = self::string($response->json('accessJwt'));
        $fresh = self::string($response->json('refreshJwt'));

        return $response->successful() && $accessJwt !== null && $fresh !== null
            ? ['access_jwt' => $accessJwt, 'refresh_jwt' => $fresh]
            : null;
    }

    /**
     * The account's profile. Status is "active", "expired" (the token was
     * refused) or "unknown" (Bluesky could not be asked).
     *
     * @return array{status: 'active'|'expired'|'unknown', profile: array<string, mixed>}
     */
    public function profile(string $accessJwt, string $did): array
    {
        try {
            $response = $this->http()->withToken($accessJwt)->get($this->url('app.bsky.actor.getProfile'), ['actor' => $did]);
        } catch (Throwable) {
            return ['status' => 'unknown', 'profile' => []];
        }

        if ($response->failed()) {
            return ['status' => $this->failure($response)->isUnauthorized() ? 'expired' : 'unknown', 'profile' => []];
        }

        $profile = $response->json();

        return ['status' => 'active', 'profile' => is_array($profile) ? $profile : []];
    }

    /**
     * Download the post's image and upload it as a blob, for the card.
     *
     * @return array<string, mixed> The blob Bluesky returned, sent back as the card's thumb.
     *
     * @throws RequestFailed
     */
    public function uploadBlob(string $accessJwt, string $imageUrl): array
    {
        [$binary, $type] = MediaDownload::fetch($imageUrl, 'Bluesky');

        $response = $this->send(fn () => $this->http()
            ->timeout((int) config('social-autopost.http.upload_timeout', 120))
            ->withToken($accessJwt)
            ->withBody($binary, $type)
            ->post($this->url('com.atproto.repo.uploadBlob')));

        $blob = $response->json('blob');

        return is_array($blob) ? $blob : throw new RequestFailed('Bluesky returned no blob for the image.', $response->status());
    }

    /**
     * Create one post: the BlueskyText composition with link facets, and the
     * link as an external embed card, with the uploaded image as its thumb.
     *
     * @param  array<string, mixed>|null  $thumb
     * @return string|null The post's at:// URI; null when Bluesky accepted the post without returning it.
     *
     * @throws RequestFailed
     */
    public function createPost(string $accessJwt, string $did, LinkPost $post, ?array $thumb = null): ?string
    {
        $text = BlueskyText::compose($post);

        $record = array_filter([
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => CarbonImmutable::now()->toIso8601ZuluString(),
            'facets' => BlueskyText::facets($text, $post->url()),
            'embed' => [
                '$type' => 'app.bsky.embed.external',
                'external' => array_filter([
                    'uri' => $post->url(),
                    'title' => $post->title(),
                    'description' => $post->descriptionText(),
                    'thumb' => $thumb,
                ], fn ($value) => $value !== null && $value !== ''),
            ],
        ], fn ($value) => $value !== []);

        $response = $this->send(fn () => $this->http()->withToken($accessJwt)->post($this->url('com.atproto.repo.createRecord'), [
            'repo' => $did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]));

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        return self::string($response->json('uri'));
    }

    /** End the session at Bluesky. Best effort: false when it refuses or cannot be reached. */
    public function deleteSession(string $refreshJwt): bool
    {
        try {
            return $this->http()->withToken($refreshJwt)->post($this->url('com.atproto.server.deleteSession'))->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function configured(): bool
    {
        return $this->setting('identifier') !== null && $this->setting('app_password') !== null;
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
            throw new RequestFailed('Could not reach Bluesky: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $response;
    }

    /** Bluesky names token problems in `error`; they are reported as 401 so the driver renews the session. */
    private function failure(Response $response): RequestFailed
    {
        $error = $response->json('error');
        $message = $response->json('message');
        $reason = self::string(is_string($message) ? $message : null) ?? self::string(is_string($error) ? $error : null) ?? 'HTTP '.$response->status();
        $status = in_array($error, ['ExpiredToken', 'InvalidToken', 'AuthenticationRequired'], true) ? 401 : $response->status();

        return new RequestFailed('Bluesky rejected the request: '.$reason, $status);
    }

    /**
     * @return array{0: string, 1: string} The identifier and app password.
     *
     * @throws NotConfigured
     */
    private function credentials(): array
    {
        $identifier = $this->setting('identifier');
        $password = $this->setting('app_password');

        if ($identifier === null || $password === null) {
            throw new NotConfigured('Set the Bluesky identifier and app password first.');
        }

        return [$identifier, $password];
    }

    private function url(string $method): string
    {
        $service = rtrim($this->setting('service') ?? 'https://bsky.social', '/');

        return $service.'/xrpc/'.$method;
    }

    private function setting(string $key): ?string
    {
        $value = config('social-autopost.networks.'.BlueskyNetwork::KEY.'.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
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
