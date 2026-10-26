<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Mastodon;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Support\MediaDownload;
use Throwable;

/**
 * Everything that talks to the configured Mastodon instance: the OAuth
 * dialog, the token exchange, verify_credentials, media uploads, posting a
 * status and revoking the token. MastodonNetwork is the only caller.
 */
final class MastodonClient
{
    /** Read the account, post statuses, upload the media they carry. */
    public const DEFAULT_SCOPES = ['read:accounts', 'write:statuses', 'write:media'];

    /** Sent on both the dialog request and the code exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = $this->setting('redirect_uri');

        return $configured ?? route(config('social-autopost.routes.name', 'social-autopost.').'callback', [
            'network' => MastodonNetwork::KEY,
        ]);
    }

    /** @throws NotConfigured */
    public function authorizationUrl(string $state): string
    {
        [$instance, $clientId] = $this->credentials();

        return $instance.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code for a token (Mastodon tokens do not expire) and read
     * the account it belongs to.
     *
     * @throws RequestFailed|NotConfigured
     */
    public function exchange(string $code): MastodonAccount
    {
        [$instance, $clientId, $clientSecret] = $this->credentials();

        $response = $this->send(fn () => $this->http()->asForm()->post($instance.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->scopes()),
        ]));

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RequestFailed('Mastodon returned no access token.', $response->status());
        }

        $verified = $this->verify($token);

        if ($verified['status'] !== 'active') {
            throw new RequestFailed('Mastodon did not return the account for the new token.');
        }

        $account = $verified['account'];
        $scope = $response->json('scope');

        return new MastodonAccount(
            accessToken: $token,
            username: self::string($account['acct'] ?? null) ?? self::string($account['username'] ?? null),
            name: self::string($account['display_name'] ?? null),
            picture: self::string($account['avatar'] ?? null),
            scopes: is_string($scope) && $scope !== '' ? explode(' ', $scope) : $this->scopes(),
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * The account behind a token. Status is "active", "expired" (the instance
     * refused the token) or "unknown" (it could not be asked).
     *
     * @return array{status: 'active'|'expired'|'unknown', account: array<string, mixed>}
     */
    public function verify(string $token): array
    {
        try {
            $response = $this->http()->withToken($token)->get($this->instance().'/api/v1/accounts/verify_credentials');
        } catch (Throwable) {
            return ['status' => 'unknown', 'account' => []];
        }

        if ($response->failed()) {
            return ['status' => in_array($response->status(), [401, 403], true) ? 'expired' : 'unknown', 'account' => []];
        }

        $account = $response->json();

        return ['status' => 'active', 'account' => is_array($account) ? $account : []];
    }

    /**
     * Download the post's image and upload it to the instance. Returns the
     * media id a status can carry.
     *
     * @throws RequestFailed
     */
    public function uploadImage(string $token, string $imageUrl): string
    {
        [$binary] = MediaDownload::fetch($imageUrl, 'Mastodon');

        $response = $this->send(fn () => $this->http()
            ->timeout((int) config('social-autopost.http.upload_timeout', 120))
            ->withToken($token)
            ->attach('file', $binary, 'media')
            ->post($this->instance().'/api/v2/media'));

        $id = $response->json('id');

        return (is_string($id) && $id !== '') || is_int($id)
            ? (string) $id
            : throw new RequestFailed('Mastodon returned no media id for the image.', $response->status());
    }

    /**
     * Post one status. The Idempotency-Key makes a retried request return the
     * first status instead of posting twice.
     *
     * @param  list<string>  $mediaIds
     * @return string|null The status id; null when the instance accepted the status without returning it.
     *
     * @throws RequestFailed
     */
    public function postStatus(string $token, string $text, array $mediaIds = []): ?string
    {
        $payload = array_filter([
            'status' => $text,
            'visibility' => $this->setting('visibility') ?? 'public',
            'media_ids' => $mediaIds,
        ], fn ($value) => $value !== []);

        $response = $this->send(fn () => $this->http()
            ->withToken($token)
            ->withHeaders(['Idempotency-Key' => hash('sha256', $text.implode(',', $mediaIds))])
            ->post($this->instance().'/api/v1/statuses', $payload));

        // A 2xx means the status exists; never fail it for a missing id, or a retry would post twice.
        $id = $response->json('id');

        return (is_string($id) && $id !== '') || is_int($id) ? (string) $id : null;
    }

    /** Revoke the token at the instance. Best effort: false when it refuses or cannot be reached. */
    public function revoke(string $token): bool
    {
        try {
            [$instance, $clientId, $clientSecret] = $this->credentials();

            return $this->http()->asForm()->post($instance.'/oauth/revoke', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $token,
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @throws NotConfigured */
    public function instance(): string
    {
        $instance = $this->setting('instance') ?? throw new NotConfigured;
        $instance = rtrim($instance, '/');

        return str_starts_with($instance, 'http') ? $instance : 'https://'.$instance;
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
            throw new RequestFailed('Could not reach Mastodon: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            $reason = $response->json('error_description') ?? $response->json('error');
            $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();

            throw new RequestFailed('Mastodon rejected the request: '.$reason, $response->status());
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: string, 2: string} The instance, client id and secret.
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

        return [$this->instance(), $id, $secret];
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $configured = config('social-autopost.networks.'.MastodonNetwork::KEY.'.scopes');

        return is_array($configured) && $configured !== []
            ? array_values(array_filter($configured, 'is_string'))
            : self::DEFAULT_SCOPES;
    }

    private function setting(string $key): ?string
    {
        $value = config('social-autopost.networks.'.MastodonNetwork::KEY.'.'.$key);

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
