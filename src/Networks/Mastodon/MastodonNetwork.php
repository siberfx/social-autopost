<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Mastodon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Contracts\SocialNetwork;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * Mastodon as a social network: one account on the configured instance,
 * connected with OAuth 2 and posting to /api/v1/statuses. Register the app
 * on the instance under Preferences → Development with the same redirect
 * URI and scopes. The token does not expire, so nothing refreshes.
 */
final class MastodonNetwork implements SocialNetwork
{
    public const KEY = 'mastodon';

    private const SESSION_KEY = 'social-autopost.oauth.'.self::KEY;

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly MastodonClient $client,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Mastodon';
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

        $verified = $this->client->verify($account->accessToken);
        $profile = $verified['account'];

        $connection = new Connection(
            network: self::KEY,
            connected: true,
            status: $verified['status'],
            name: self::string($profile['display_name'] ?? null) ?? self::string($profile['acct'] ?? null) ?? $account->name ?? $account->username,
            picture: self::string($profile['avatar'] ?? null) ?? $account->picture,
            scopes: $account->scopes,
            connectedAt: $account->connectedAt,
        );

        $this->remember($account, $connection);

        return $connection;
    }

    /** @throws NotConfigured */
    public function beginConnection(): string
    {
        $state = Str::random(40);
        $url = $this->client->authorizationUrl($state);

        session()->put(self::SESSION_KEY, $state);

        return $url;
    }

    /** @throws ConnectionFailed */
    public function completeConnection(Request $request): Connection
    {
        $expected = session()->pull(self::SESSION_KEY);
        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            throw $error === 'access_denied'
                ? new ConnectionFailed('cancelled', 'Connecting was cancelled on Mastodon.')
                : new ConnectionFailed('error', 'Mastodon: '.$this->reason($request));
        }

        $state = $request->query('state');

        if (! is_string($expected) || $expected === '' || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new ConnectionFailed('invalid_state', 'The login attempt expired or was replayed. Connect again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ConnectionFailed('missing_code', 'Mastodon did not return an authorization code. Connect again.');
        }

        try {
            $account = $this->client->exchange($code);
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

    /** Revokes the token at the instance (best effort) and forgets the stored data either way. */
    public function disconnect(): bool
    {
        $account = $this->stored();
        $revoked = $account === null || $this->client->revoke($account->accessToken);

        if ($account !== null) {
            Cache::forget($this->statusKey($account));
        }

        $this->store->forget(self::KEY);

        return $revoked;
    }

    /**
     * Posts the MastodonText composition, with the post's image uploaded and
     * attached when it carries one. A video is not posted; the link is.
     *
     * @throws NotConnected|RequestFailed
     */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $account = $this->stored() ?? throw new NotConnected('Mastodon is not connected.');
        $imageUrl = $post->imageUrl();

        try {
            $media = $imageUrl !== null ? [$this->client->uploadImage($account->accessToken, $imageUrl)] : [];

            return $this->client->postStatus($account->accessToken, MastodonText::compose($post), $media);
        } catch (RequestFailed $e) {
            if ($e->isUnauthorized()) {
                $this->remember($account, $this->connectionFrom($account, 'expired'));
            }

            throw $e;
        }
    }

    private function stored(): ?MastodonAccount
    {
        $data = $this->store->get(self::KEY);

        return $data === null ? null : MastodonAccount::fromArray($data);
    }

    private function connectionFrom(MastodonAccount $account, string $status): Connection
    {
        return new Connection(
            network: self::KEY,
            connected: true,
            status: $status,
            name: $account->name ?? $account->username,
            picture: $account->picture,
            scopes: $account->scopes,
            connectedAt: $account->connectedAt,
        );
    }

    /** Only plain arrays go into the cache, see Connection::toCache(). */
    private function remember(MastodonAccount $account, Connection $connection): void
    {
        Cache::put($this->statusKey($account), $connection->toCache(), (int) config('social-autopost.status_cache_ttl', 300));
    }

    private function statusKey(MastodonAccount $account): string
    {
        return 'social-autopost:status:'.self::KEY.':'.sha1($account->accessToken);
    }

    private function reason(Request $request): string
    {
        $description = $request->query('error_description');
        $reason = is_string($description) && $description !== '' ? $description : (string) $request->query('error');

        return mb_substr($reason, 0, 200);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
