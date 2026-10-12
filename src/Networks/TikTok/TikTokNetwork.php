<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\TikTok;

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
 * TikTok as a social network: Login Kit (OAuth 2.0 v2) and the Content
 * Posting API with Direct Post. TikTok posts media, not links: a post needs
 * a video or an image, and the commentary becomes the caption.
 *
 * The access token lasts a day. It is refreshed before use once expired, and
 * a request TikTok refuses with 401 is retried once after a refresh. The
 * connection itself lasts as long as the refresh token: a year.
 */
final class TikTokNetwork implements SocialNetwork
{
    public const KEY = 'tiktok';

    private const SESSION_KEY = 'social-autopost.oauth.'.self::KEY;

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
        return 'TikTok';
    }

    public function isConnected(): bool
    {
        return $this->account() !== null;
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
        $state = Str::random(40);
        $url = $this->oauth->authorizationUrl($state);

        session()->put(self::SESSION_KEY, $state);

        return $url;
    }

    /**
     * Checks the state, exchanges the code and stores the account. The pending
     * state is always consumed, even when the flow fails.
     *
     * @throws ConnectionFailed
     */
    public function completeConnection(Request $request): Connection
    {
        $expected = session()->pull(self::SESSION_KEY);
        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            throw $error === 'access_denied'
                ? new ConnectionFailed('cancelled', 'The connection was cancelled on TikTok.')
                : new ConnectionFailed('error', 'TikTok refused the connection: '.self::reason($request));
        }

        $state = $request->query('state');

        if (! is_string($expected) || $expected === '' || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new ConnectionFailed('invalid_state', 'The TikTok sign-in expired or could not be verified. Connect again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new ConnectionFailed('missing_code', 'TikTok did not return an authorization code. Connect again.');
        }

        try {
            $account = $this->oauth->exchange($code);
        } catch (NotConfigured $e) {
            throw $e;
        } catch (RequestFailed $e) {
            throw new ConnectionFailed('exchange_failed', $e->getMessage(), $e);
        }

        $this->store->put(self::KEY, $account->toArray());

        $connection = $this->connectionFrom($account, 'active');
        $this->remember($account->accessToken, $connection);

        return $connection;
    }

    /** Revokes the tokens at TikTok. The stored connection is forgotten either way. */
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
     * Posts the video, or else the image, refreshing the token first when it
     * has expired and once more on a 401.
     *
     * @throws NotConnected|RequestFailed
     */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        $account = $this->account() ?? throw new NotConnected('TikTok is not connected.');

        if ($post->videoUrl() === null && $post->imageUrl() === null) {
            throw new RequestFailed('TikTok posts need a video or an image: add ->video() or ->image() to the post.', 422);
        }

        if ($account->accessExpired()) {
            $account = $this->refreshed($account) ?? $account;
        }

        try {
            return $this->posts->publish($account->accessToken, $post, $this->privacyLevel());
        } catch (RequestFailed $e) {
            if (! $e->isUnauthorized()) {
                throw $e;
            }

            $fresh = $this->refreshed($account);

            if ($fresh === null) {
                $this->remember($account->accessToken, $this->connectionFrom($account, 'expired'));

                throw $e;
            }

            return $this->posts->publish($fresh->accessToken, $post, $this->privacyLevel());
        }
    }

    /** The account lookup, refreshing first when the token has expired or TikTok refuses it. */
    private function fetchStatus(TikTokAccount $account): Connection
    {
        if ($account->accessExpired() && ($fresh = $this->refreshed($account)) !== null) {
            $account = $fresh;
        }

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
            name: self::string($user['display_name'] ?? null) ?? $account->name,
            picture: self::string($user['avatar_url'] ?? null) ?? $account->picture,
            scopes: $account->scopes,
            connectedAt: $account->connectedAt,
            expiresAt: $account->refreshExpiresAt,
        );

        $this->remember($account->accessToken, $connection);

        return $connection;
    }

    /** Trades the stored refresh token for a new pair, stores it and returns the renewed account. Null when TikTok refuses. */
    private function refreshed(TikTokAccount $account): ?TikTokAccount
    {
        if ($account->refreshToken === null) {
            return null;
        }

        $tokens = $this->oauth->refresh($account->refreshToken);

        if ($tokens === null) {
            return null;
        }

        $fresh = $account->withTokens($tokens);
        $this->store->put(self::KEY, $fresh->toArray());

        return $fresh;
    }

    /** One of PostsClient::PRIVACY_LEVELS; PUBLIC_TO_EVERYONE when the setting is missing or unknown. */
    private function privacyLevel(): string
    {
        $level = config('social-autopost.networks.'.self::KEY.'.privacy_level');

        return is_string($level) && in_array($level, PostsClient::PRIVACY_LEVELS, true) ? $level : 'PUBLIC_TO_EVERYONE';
    }

    private function account(): ?TikTokAccount
    {
        $data = $this->store->get(self::KEY);

        return $data === null ? null : TikTokAccount::fromArray($data);
    }

    private function connectionFrom(TikTokAccount $account, string $status): Connection
    {
        return new Connection(
            network: self::KEY,
            connected: true,
            status: $status,
            name: $account->name,
            picture: $account->picture,
            scopes: $account->scopes,
            connectedAt: $account->connectedAt,
            expiresAt: $account->refreshExpiresAt,
        );
    }

    /** Only plain arrays go into the cache, so a store that refuses objects never breaks it. */
    private function remember(string $accessToken, Connection $connection): void
    {
        Cache::put($this->cacheKey($accessToken), $connection->toCache(), (int) config('social-autopost.status_cache_ttl', 300));
    }

    private function cacheKey(string $accessToken): string
    {
        return 'social-autopost:status:'.self::KEY.':'.sha1($accessToken);
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
