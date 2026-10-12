<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Instagram;

use Carbon\CarbonImmutable;
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
 * Posts to an Instagram professional (Business or Creator) account with the
 * Instagram API with Instagram Login. Instagram posts media, not links: a
 * post needs an image or a video, and the commentary becomes the caption.
 *
 * The long-lived token lasts 60 days and is refreshed once fewer than
 * REFRESH_DAYS remain. Instagram has no API to revoke it, so disconnecting
 * forgets it here; people remove the app in Instagram's own settings.
 */
final class InstagramNetwork implements SocialNetwork
{
    public const KEY = 'instagram';

    private const SESSION_KEY = 'social-autopost.oauth.'.self::KEY;

    /** Refresh the token when fewer days remain; it is then at least a day old, as Instagram requires. */
    private const REFRESH_DAYS = 30;

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly InstagramClient $client,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Instagram';
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

        $account = $this->renewed($account);
        $cached = Cache::get($this->statusKey($account));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $profile = $this->client->profile($account->accessToken);
        $user = $profile['user'];

        $connection = new Connection(
            network: self::KEY,
            connected: true,
            status: $profile['status'],
            name: self::string($user['username'] ?? null) ?? $account->username ?? $account->name,
            picture: self::string($user['profile_picture_url'] ?? null) ?? $account->picture,
            connectedAt: $account->connectedAt,
            expiresAt: $account->expiresAt,
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
                ? new ConnectionFailed('cancelled', 'Connecting was cancelled on Instagram.')
                : new ConnectionFailed('error', 'Instagram: '.$this->reason($request));
        }

        $state = $request->query('state');

        if (! is_string($expected) || $expected === '' || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new ConnectionFailed('invalid_state', 'The login attempt expired or was replayed. Connect again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ConnectionFailed('missing_code', 'Instagram did not return an authorization code. Connect again.');
        }

        // Instagram appends "#_" to the redirect; a careless copy can carry it into the code.
        $code = Str::before($code, '#_');

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

    /** Instagram has no revocation API: the connection is forgotten here, and false says it was not revoked there. */
    public function disconnect(): bool
    {
        $account = $this->stored();

        if ($account === null) {
            return true;
        }

        Cache::forget($this->statusKey($account));
        $this->store->forget(self::KEY);

        return false;
    }

    /** @throws NotConnected|RequestFailed */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $account = $this->stored() ?? throw new NotConnected('Instagram is not connected.');

        if ($post->videoUrl() === null && $post->imageUrl() === null) {
            throw new RequestFailed('Instagram posts need an image or a video: add ->image() or ->video() to the post.', 422);
        }

        $account = $this->renewed($account);

        try {
            return $this->client->publish($account->accessToken, $account->userId, $post);
        } catch (RequestFailed $e) {
            if ($e->isUnauthorized()) {
                $this->remember($account, $this->connectionFrom($account, 'expired'));
            }

            throw $e;
        }
    }

    /**
     * The account with a refreshed token once fewer than REFRESH_DAYS remain,
     * stored for next time. Unchanged when no refresh is due or Instagram refuses.
     */
    private function renewed(InstagramAccount $account): InstagramAccount
    {
        $now = CarbonImmutable::now();

        if ($account->expiresAt === null || $account->expiresAt->isBefore($now) || $account->expiresAt->isAfter($now->addDays(self::REFRESH_DAYS))) {
            return $account;
        }

        $fresh = $this->client->refresh($account->accessToken);

        if ($fresh === null) {
            return $account;
        }

        Cache::forget($this->statusKey($account));
        $account = $account->withToken($fresh['access_token'], $fresh['expires_at']);
        $this->store->put(self::KEY, $account->toArray());

        return $account;
    }

    private function stored(): ?InstagramAccount
    {
        $data = $this->store->get(self::KEY);

        return $data === null ? null : InstagramAccount::fromArray($data);
    }

    private function connectionFrom(InstagramAccount $account, string $status): Connection
    {
        return new Connection(
            network: self::KEY,
            connected: true,
            status: $status,
            name: $account->username ?? $account->name,
            picture: $account->picture,
            connectedAt: $account->connectedAt,
            expiresAt: $account->expiresAt,
        );
    }

    /** Only plain arrays go into the cache, see Connection::toCache(). */
    private function remember(InstagramAccount $account, Connection $connection): void
    {
        Cache::put($this->statusKey($account), $connection->toCache(), (int) config('social-autopost.status_cache_ttl', 300));
    }

    private function statusKey(InstagramAccount $account): string
    {
        return 'social-autopost:status:'.self::KEY.':'.sha1($account->accessToken);
    }

    private function reason(Request $request): string
    {
        foreach (['error_description', 'error_reason', 'error'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 200);
            }
        }

        return 'unknown error';
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
