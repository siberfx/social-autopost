<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
use Throwable;

/**
 * LinkedIn as a social network: one member account connected through
 * "Sign In with LinkedIn" and posts through the Posts API.
 *
 * The stored data is an array: access_token, author_urn, name, email,
 * picture, scopes, expires_at and connected_at. The access token never
 * leaves this class; Connection carries the profile and status only.
 */
final class LinkedInNetwork implements SocialNetwork
{
    private const SESSION_KEY = 'social-autopost.oauth.linkedin';

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly OAuthClient $oauth,
        private readonly PostsClient $posts,
    ) {}

    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    public function isConnected(): bool
    {
        return $this->stored() !== null;
    }

    /** Cached per token for status_cache_ttl seconds; LinkedIn is asked at most once in between. */
    public function connection(): Connection
    {
        $stored = $this->stored();

        if ($stored === null) {
            return Connection::disconnected($this->key());
        }

        $token = $this->token($stored);
        $cached = Cache::get($this->cacheKey($token));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $connection = $this->fetchStatus($stored);
        $this->remember($token, $connection);

        return $connection;
    }

    /** @throws NotConfigured When the client id or secret is missing. */
    public function beginConnection(): string
    {
        $state = Str::random(40);
        $url = $this->oauth->authorizationUrl($state);

        session()->put(self::SESSION_KEY, $state);

        return $url;
    }

    /** @throws ConnectionFailed */
    public function completeConnection(Request $request): Connection
    {
        $expected = session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new ConnectionFailed('invalid_state', 'The LinkedIn login expired or was replayed. Connect again.');
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            if (str_starts_with($error, 'user_cancelled')) {
                throw new ConnectionFailed('cancelled', 'The LinkedIn login was cancelled.');
            }

            $reason = $request->query('error_description');

            throw new ConnectionFailed('error', Str::limit(is_string($reason) && $reason !== '' ? $reason : $error, 300));
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ConnectionFailed('missing_code', 'LinkedIn did not return an authorization code. Connect again.');
        }

        try {
            $stored = $this->oauth->exchange($code);
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('LinkedIn token exchange failed.', [
                'error' => $e instanceof RequestException ? $e->response->body() : $e->getMessage(),
            ]);

            throw new ConnectionFailed('exchange_failed', 'LinkedIn did not accept the authorization code. Check the redirect URI and try again.', $e);
        }

        $this->store->put($this->key(), $stored);

        return $this->toConnection($stored, 'active');
    }

    /** Revokes at LinkedIn (best effort) and forgets the stored data either way. */
    public function disconnect(): bool
    {
        $stored = $this->stored();
        $revoked = $stored === null || $this->oauth->revoke($this->token($stored));

        if ($stored !== null) {
            Cache::forget($this->cacheKey($this->token($stored)));
        }

        $this->store->forget($this->key());

        return $revoked;
    }

    /**
     * @return string|null The post URN, or null when LinkedIn accepted the post without one.
     *
     * @throws RequestFailed
     */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $stored = $this->stored() ?? throw new NotConnected;
        $token = $this->token($stored);

        try {
            return $this->posts->create($token, (string) ($stored['author_urn'] ?? ''), $post);
        } catch (RequestFailed $e) {
            // LinkedIn refused the token: show it as expired until the admin connects again.
            if ($e->isUnauthorized()) {
                $this->remember($token, $this->withStatus($this->connection(), 'expired'));
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function stored(): ?array
    {
        return $this->store->get($this->key());
    }

    /** @param  array<string, mixed>  $stored */
    private function token(array $stored): string
    {
        return (string) ($stored['access_token'] ?? '');
    }

    /** @param  array<string, mixed>  $stored */
    private function fetchStatus(array $stored): Connection
    {
        $token = $this->token($stored);
        $introspection = $this->oauth->introspect($token);

        if ($introspection === null) {
            return $this->toConnection($stored, 'unknown');
        }

        $active = (bool) ($introspection['active'] ?? false);
        $status = is_string($introspection['status'] ?? null)
            ? $introspection['status']
            : ($active ? 'active' : 'expired');
        $profile = $active ? $this->oauth->userinfo($token) : null;

        return new Connection(
            network: $this->key(),
            connected: true,
            status: $status,
            name: self::string($profile['name'] ?? null) ?? self::string($stored['name'] ?? null),
            email: self::string($profile['email'] ?? null) ?? self::string($stored['email'] ?? null),
            picture: self::string($profile['picture'] ?? null) ?? self::string($stored['picture'] ?? null),
            scopes: isset($introspection['scope']) ? OAuthClient::scopes($introspection['scope']) : self::scopes($stored),
            connectedAt: isset($introspection['created_at'])
                ? CarbonImmutable::createFromTimestamp((int) $introspection['created_at'])
                : self::date($stored, 'connected_at'),
            expiresAt: isset($introspection['expires_at'])
                ? CarbonImmutable::createFromTimestamp((int) $introspection['expires_at'])
                : self::date($stored, 'expires_at'),
        );
    }

    /** @param  array<string, mixed>  $stored */
    private function toConnection(array $stored, string $status): Connection
    {
        return new Connection(
            network: $this->key(),
            connected: true,
            status: $status,
            name: self::string($stored['name'] ?? null),
            email: self::string($stored['email'] ?? null),
            picture: self::string($stored['picture'] ?? null),
            scopes: self::scopes($stored),
            connectedAt: self::date($stored, 'connected_at'),
            expiresAt: self::date($stored, 'expires_at'),
        );
    }

    private function withStatus(Connection $connection, string $status): Connection
    {
        return new Connection(
            network: $connection->network,
            connected: $connection->connected,
            status: $status,
            name: $connection->name,
            email: $connection->email,
            picture: $connection->picture,
            scopes: $connection->scopes,
            connectedAt: $connection->connectedAt,
            expiresAt: $connection->expiresAt,
        );
    }

    /** Only plain arrays go into the cache; see Connection::toCache(). */
    private function remember(string $token, Connection $connection): void
    {
        Cache::put($this->cacheKey($token), $connection->toCache(), $this->cacheTtl());
    }

    private function cacheKey(string $token): string
    {
        return 'social-autopost:status:linkedin:'.hash('sha256', $token);
    }

    private function cacheTtl(): int
    {
        return (int) config('social-autopost.status_cache_ttl', 300);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<string>
     */
    private static function scopes(array $stored): array
    {
        $scopes = [];

        foreach (is_array($stored['scopes'] ?? null) ? $stored['scopes'] : [] as $scope) {
            if (is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /** @param  array<string, mixed>  $stored */
    private static function date(array $stored, string $key): ?CarbonImmutable
    {
        $value = self::string($stored[$key] ?? null);

        return $value !== null ? CarbonImmutable::parse($value) : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
