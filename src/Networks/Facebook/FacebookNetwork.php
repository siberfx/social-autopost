<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Facebook;

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
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * Posts to one Facebook Page. Connect runs Facebook Login and keeps the Page's
 * own token, which does not expire, so nothing refreshes. Disconnect removes
 * the app's permissions with the stored user token, which revokes the Page token too.
 */
final class FacebookNetwork implements SocialNetwork
{
    public const KEY = 'facebook';

    private const SESSION_KEY = 'social-autopost.oauth.'.self::KEY;

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly GraphClient $graph,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Facebook';
    }

    public function isConnected(): bool
    {
        return $this->stored() !== null;
    }

    public function connection(): Connection
    {
        $stored = $this->stored();

        if ($stored === null) {
            return Connection::disconnected(self::KEY);
        }

        $cached = Cache::get($this->statusKey($stored));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $connection = $this->fetchStatus($stored);
        $this->remember($stored, $connection);

        return $connection;
    }

    /** @throws ConnectionFailed */
    public function beginConnection(): string
    {
        $state = Str::random(40);
        $url = $this->graph->authorizationUrl($state);

        session()->put(self::SESSION_KEY, $state);

        return $url;
    }

    /** @throws ConnectionFailed */
    public function completeConnection(Request $request): Connection
    {
        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            throw $error === 'access_denied'
                ? new ConnectionFailed('cancelled', 'Connecting was cancelled on Facebook.')
                : new ConnectionFailed('error', 'Facebook: '.$this->reason($request));
        }

        $expected = session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || $expected === '' || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new ConnectionFailed('invalid_state', 'The login attempt expired or was replayed. Connect again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ConnectionFailed('missing_code', 'Facebook did not return an authorization code. Connect again.');
        }

        try {
            $stored = $this->graph->exchange($code);
        } catch (RequestFailed $e) {
            throw new ConnectionFailed('exchange_failed', $e->getMessage(), $e);
        }

        $this->store->put(self::KEY, $stored->toArray());

        return new Connection(
            network: self::KEY,
            connected: true,
            status: 'active',
            name: $stored->name,
            connectedAt: $stored->connectedAt,
        );
    }

    public function disconnect(): bool
    {
        $stored = $this->stored();

        if ($stored !== null) {
            Cache::forget($this->statusKey($stored));
        }

        $revoked = match (true) {
            $stored === null => true,
            $stored->userAccessToken === null => false,
            default => $this->graph->revoke($stored->userAccessToken),
        };

        $this->store->forget(self::KEY);

        return $revoked;
    }

    /** @throws NotConnected|RequestFailed */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $stored = $this->stored() ?? throw new NotConnected('Facebook is not connected.');

        try {
            return $this->graph->postLink($stored->accessToken, $stored->pageId, $post);
        } catch (RequestFailed $e) {
            if ($e->isUnauthorized()) {
                $this->remember($stored, $this->connected($stored, 'expired'));
            }

            throw $e;
        }
    }

    private function stored(): ?FacebookConnection
    {
        $data = $this->store->get(self::KEY);

        return $data === null ? null : FacebookConnection::fromArray($data);
    }

    private function fetchStatus(FacebookConnection $stored): Connection
    {
        $debug = $this->graph->debugToken($stored->accessToken);

        if ($debug === null) {
            return $this->connected($stored, 'unknown');
        }

        if (! ($debug['is_valid'] ?? false)) {
            return $this->connected($stored, 'expired');
        }

        $profile = $this->graph->pageProfile($stored->accessToken, $stored->pageId) ?? [];
        $expiresAt = is_numeric($debug['expires_at'] ?? null) ? (int) $debug['expires_at'] : 0;

        return new Connection(
            network: self::KEY,
            connected: true,
            status: 'active',
            name: self::string($profile['name'] ?? null) ?? $stored->name,
            picture: self::string($profile['picture']['data']['url'] ?? null),
            connectedAt: $stored->connectedAt,
            // 0 means the Page token never expires.
            expiresAt: $expiresAt > 0 ? CarbonImmutable::createFromTimestamp($expiresAt) : null,
        );
    }

    private function connected(FacebookConnection $stored, string $status): Connection
    {
        return new Connection(
            network: self::KEY,
            connected: true,
            status: $status,
            name: $stored->name,
            connectedAt: $stored->connectedAt,
        );
    }

    /** Only plain arrays go into the cache, see Connection::toCache(). */
    private function remember(FacebookConnection $stored, Connection $connection): void
    {
        Cache::put($this->statusKey($stored), $connection->toCache(), (int) config('social-autopost.status_cache_ttl', 300));
    }

    private function statusKey(FacebookConnection $stored): string
    {
        return 'social-autopost:status:'.self::KEY.':'.sha1($stored->accessToken);
    }

    private function reason(Request $request): string
    {
        foreach (['error_description', 'error_reason', 'error'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'unknown error';
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
