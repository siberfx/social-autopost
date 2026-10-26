<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Bluesky;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Contracts\SocialNetwork;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Throwable;

/**
 * Bluesky as a social network. There is no OAuth consent screen: the account's
 * handle and app password live in .env, and "Connect" signs in with them on
 * the server — beginConnection sends the browser straight to the package
 * callback, which creates the session. Posts carry the link as an external
 * embed card, with the post's image as its thumbnail.
 *
 * The access token lasts hours and is refreshed with the refresh token; when
 * that fails too, the driver simply signs in again with the app password.
 */
final class BlueskyNetwork implements SocialNetwork
{
    public const KEY = 'bluesky';

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly BlueskyClient $client,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Bluesky';
    }

    public function isConnected(): bool
    {
        return $this->stored() !== null;
    }

    public function connection(): Connection
    {
        $account = $this->stored();

        if ($account === null) {
            return Connection::disconnected(self::KEY);
        }

        $cached = Cache::get($this->statusKey($account));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $profile = $this->client->profile($account->accessJwt, $account->did);

        if ($profile['status'] === 'expired' && ($fresh = $this->renewed($account)) !== null) {
            $account = $fresh;
            $profile = $this->client->profile($fresh->accessJwt, $fresh->did);
        }

        $user = $profile['profile'];

        $connection = new Connection(
            network: self::KEY,
            connected: true,
            status: $profile['status'],
            name: self::string($user['displayName'] ?? null) ?? self::string($user['handle'] ?? null) ?? $account->name ?? $account->handle,
            picture: self::string($user['avatar'] ?? null) ?? $account->picture,
            connectedAt: $account->connectedAt,
        );

        $this->remember($account, $connection);

        return $connection;
    }

    /**
     * No consent screen to visit: the browser goes straight to the package
     * callback, which signs in with the configured app password.
     *
     * @throws NotConfigured When the identifier or app password is missing.
     */
    public function beginConnection(): string
    {
        if (! $this->client->configured()) {
            throw new NotConfigured('Set the Bluesky identifier and app password first.');
        }

        return route(config('social-autopost.routes.name', 'social-autopost.').'callback', ['network' => self::KEY]);
    }

    /** Signs in with the configured app password and stores the session. @throws ConnectionFailed */
    public function completeConnection(Request $request): Connection
    {
        try {
            $account = $this->client->createSession();
        } catch (NotConfigured $e) {
            throw $e;
        } catch (RequestFailed $e) {
            throw new ConnectionFailed('exchange_failed', $e->getMessage(), $e);
        }

        $this->store->put(self::KEY, $account->toArray());

        $connection = $this->connectionFrom($account, 'active');
        $this->remember($account, $connection);

        return $connection;
    }

    /** Ends the session at Bluesky (best effort) and forgets it either way. The app password in .env stays valid. */
    public function disconnect(): bool
    {
        $account = $this->stored();
        $revoked = $account === null || $this->client->deleteSession($account->refreshJwt);

        if ($account !== null) {
            Cache::forget($this->statusKey($account));
        }

        $this->store->forget(self::KEY);

        return $revoked;
    }

    /**
     * Posts the BlueskyText composition with the link as an embed card,
     * renewing the session once when Bluesky refuses the token.
     *
     * @throws NotConnected|RequestFailed
     */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $account = $this->stored() ?? throw new NotConnected('Bluesky is not connected.');

        try {
            return $this->post($account, $post);
        } catch (RequestFailed $e) {
            if (! $e->isUnauthorized()) {
                throw $e;
            }

            $fresh = $this->renewed($account);

            if ($fresh === null) {
                $this->remember($account, $this->connectionFrom($account, 'expired'));

                throw $e;
            }

            return $this->post($fresh, $post);
        }
    }

    /** Uploads the card image (when there is one) and creates the post. @throws RequestFailed */
    private function post(BlueskyAccount $account, LinkPost $post): ?string
    {
        $imageUrl = $post->imageUrl();
        $thumb = $imageUrl !== null ? $this->client->uploadBlob($account->accessJwt, $imageUrl) : null;

        return $this->client->createPost($account->accessJwt, $account->did, $post, $thumb);
    }

    /**
     * A fresh session: the refresh token when Bluesky still takes it, or a new
     * sign-in with the app password. Null when both fail.
     */
    private function renewed(BlueskyAccount $account): ?BlueskyAccount
    {
        Cache::forget($this->statusKey($account));

        $tokens = $this->client->refreshSession($account->refreshJwt);

        if ($tokens !== null) {
            $fresh = $account->withTokens($tokens['access_jwt'], $tokens['refresh_jwt']);
        } else {
            try {
                $fresh = $this->client->createSession();
            } catch (Throwable) {
                return null;
            }
        }

        $this->store->put(self::KEY, $fresh->toArray());

        return $fresh;
    }

    private function stored(): ?BlueskyAccount
    {
        $data = $this->store->get(self::KEY);

        return $data === null ? null : BlueskyAccount::fromArray($data);
    }

    private function connectionFrom(BlueskyAccount $account, string $status): Connection
    {
        return new Connection(
            network: self::KEY,
            connected: true,
            status: $status,
            name: $account->name ?? $account->handle,
            picture: $account->picture,
            connectedAt: $account->connectedAt,
        );
    }

    /** Only plain arrays go into the cache, see Connection::toCache(). */
    private function remember(BlueskyAccount $account, Connection $connection): void
    {
        Cache::put($this->statusKey($account), $connection->toCache(), (int) config('social-autopost.status_cache_ttl', 300));
    }

    private function statusKey(BlueskyAccount $account): string
    {
        return 'social-autopost:status:'.self::KEY.':'.sha1($account->accessJwt);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
