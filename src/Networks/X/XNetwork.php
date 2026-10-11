<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

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
 * X (Twitter) as a social network: OAuth 2.0 with PKCE, posting to /2/tweets.
 *
 * The access token lasts about two hours. A request X refuses with 401 is
 * retried once after a refresh, and the rotated refresh token is stored each time.
 */
final class XNetwork implements SocialNetwork
{
    private const KEY = 'x';

    /** Session key for the OAuth state and PKCE verifier of the connection in progress. */
    private const SESSION_KEY = 'social-autopost.oauth.x';

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly OAuthClient $oauth,
        private readonly PostsClient $posts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'X';
    }

    public function isConnected(): bool
    {
        return $this->store->get(self::KEY) !== null;
    }

    public function connection(): Connection
    {
        $account = $this->account();

        if ($account === null) {
            return Connection::disconnected(self::KEY);
        }

        $cached = Cache::get($this->cacheKey($account->accessToken));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        return $this->fetchStatus($account);
    }

    /** @throws NotConfigured */
    public function beginConnection(): string
    {
        $verifier = OAuthClient::newCodeVerifier();
        $state = Str::random(40);

        session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

        return $this->oauth->authorizationUrl($state, OAuthClient::codeChallenge($verifier));
    }

    /**
     * Checks the state, exchanges the code with the stored verifier and stores
     * the account. The pending state is always consumed, even when the flow fails.
     *
     * @throws ConnectionFailed
     */
    public function completeConnection(Request $request): Connection
    {
        $pending = session()->pull(self::SESSION_KEY);

        if (is_string($request->query('error'))) {
            throw $request->query('error') === 'access_denied'
                ? new ConnectionFailed('cancelled', 'The connection was cancelled on X.')
                : new ConnectionFailed('error', 'X refused the connection: '.self::reason($request));
        }

        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($pending['verifier'] ?? null)) {
            throw new ConnectionFailed('invalid_state', 'The X sign-in expired or was already used. Connect again.');
        }

        if (! hash_equals($pending['state'], (string) $request->query('state', ''))) {
            throw new ConnectionFailed('invalid_state', 'The X sign-in could not be verified. Connect again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ConnectionFailed('missing_code', 'X did not return an authorization code. Connect again.');
        }

        try {
            $account = $this->oauth->exchange($code, $pending['verifier']);
        } catch (NotConfigured $e) {
            throw $e;
        } catch (RequestFailed $e) {
            throw new ConnectionFailed('exchange_failed', $e->getMessage(), $e);
        }

        $this->store->put(self::KEY, $account->toArray());

        $connection = new Connection(
            network: self::KEY,
            connected: true,
            status: 'active',
            name: $account->name ?? $account->username,
            picture: $account->picture,
            scopes: $account->scopes,
            connectedAt: $account->connectedAt,
        );

        $this->remember($account->accessToken, $connection);

        return $connection;
    }

    /** Revokes the access token at X. The stored connection is forgotten either way. */
    public function disconnect(): bool
    {
        $account = $this->account();
        $revoked = $account === null || $this->oauth->revoke($account->accessToken);

        if ($account !== null) {
            Cache::forget($this->cacheKey($account->accessToken));
        }

        $this->store->forget(self::KEY);

        return $revoked;
    }

    /**
     * Posts the link as the X text from XText, refreshing the token once on a 401.
     *
     * @throws NotConnected|RequestFailed
     */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $account = $this->account() ?? throw new NotConnected;
        $text = XText::compose($post);

        try {
            return $this->posts->create($account->accessToken, $text);
        } catch (RequestFailed $e) {
            if (! $e->isUnauthorized()) {
                throw $e;
            }

            $fresh = $this->refreshed($account);

            if ($fresh === null) {
                $this->remember($account->accessToken, $this->connectionFrom($account, 'expired'));

                throw $e;
            }

            return $this->posts->create($fresh->accessToken, $text);
        }
    }

    /** The account lookup, refreshing once when X says the token expired. Status comes from the stored data plus the profile. */
    private function fetchStatus(XAccount $account): Connection
    {
        $profile = $this->oauth->profile($account->accessToken);

        if ($profile['status'] === 'expired' && ($fresh = $this->refreshed($account)) !== null) {
            $account = $fresh;
            $profile = $this->oauth->profile($fresh->accessToken);
        }

        $user = $profile['user'];

        $connection = new Connection(
            network: self::KEY,
            connected: true,
            status: $profile['status'],
            name: self::string($user['name'] ?? null) ?? $account->name ?? $account->username,
            picture: self::string($user['profile_image_url'] ?? null) ?? $account->picture,
            scopes: $account->scopes,
            connectedAt: $account->connectedAt,
        );

        $this->remember($account->accessToken, $connection);

        return $connection;
    }

    /** Trades the stored refresh token for a new pair, stores both and returns the renewed account. Null when X refuses. */
    private function refreshed(XAccount $account): ?XAccount
    {
        if ($account->refreshToken === null) {
            return null;
        }

        $tokens = $this->oauth->refresh($account->refreshToken);

        if ($tokens === null) {
            return null;
        }

        $fresh = $account->withTokens($tokens['access_token'], $tokens['refresh_token']);
        $this->store->put(self::KEY, $fresh->toArray());

        return $fresh;
    }

    private function account(): ?XAccount
    {
        $data = $this->store->get(self::KEY);

        return $data === null ? null : XAccount::fromArray($data);
    }

    private function connectionFrom(XAccount $account, string $status): Connection
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

    /** Only plain arrays go into the cache, so a store that refuses objects never breaks it. */
    private function remember(string $accessToken, Connection $connection): void
    {
        Cache::put($this->cacheKey($accessToken), $connection->toCache(), $this->cacheTtl());
    }

    private function cacheKey(string $accessToken): string
    {
        return 'social-autopost:status:'.self::KEY.':'.sha1($accessToken);
    }

    private function cacheTtl(): int
    {
        return (int) config('social-autopost.status_cache_ttl', 300);
    }

    private static function reason(Request $request): string
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
