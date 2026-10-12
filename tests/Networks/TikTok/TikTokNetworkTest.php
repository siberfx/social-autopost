<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Models\SocialConnection;
use Siberfx\SocialAutopost\Networks\TikTok\TikTokAccount;
use Siberfx\SocialAutopost\Networks\TikTok\TikTokNetwork;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const TT_TOKEN = 'https://open.tiktokapis.com/v2/oauth/token/';
const TT_REVOKE = 'https://open.tiktokapis.com/v2/oauth/revoke/';
const TT_USER = 'https://open.tiktokapis.com/v2/user/info/*';
const TT_CREATOR = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';
const TT_VIDEO = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
const TT_CONTENT = 'https://open.tiktokapis.com/v2/post/publish/content/init/';

beforeEach(function () {
    config()->set('social-autopost.networks.tiktok.client_key', 'tt-key');
    config()->set('social-autopost.networks.tiktok.client_secret', 'tt-secret');
    config()->set('social-autopost.networks.tiktok.redirect_uri', null);
    config()->set('social-autopost.networks.tiktok.privacy_level', 'PUBLIC_TO_EVERYONE');
});

function tiktok(): TikTokNetwork
{
    return app(TikTokNetwork::class);
}

/** Store a TikTok connection, as a completed login would. */
function ttStore(string $access = 'access-1', ?string $refresh = 'refresh-1', ?CarbonImmutable $expiresAt = null): void
{
    app(ConnectionStore::class)->put('tiktok', (new TikTokAccount(
        accessToken: $access,
        openId: 'open-1',
        refreshToken: $refresh,
        name: 'Ada',
        picture: 'https://p16.example/ada.jpg',
        scopes: ['user.info.basic', 'video.publish'],
        expiresAt: $expiresAt ?? CarbonImmutable::now()->addHours(20),
        refreshExpiresAt: CarbonImmutable::now()->addDays(300),
        connectedAt: CarbonImmutable::now()->subDays(65),
    ))->toArray());
}

function ttPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => 'published']);
}

function ttCallback(array $query, ?string $state = 'expected-state'): HttpRequest
{
    if ($state !== null) {
        session()->put('social-autopost.oauth.tiktok', $state);
    }

    return HttpRequest::create('/social/tiktok/callback', 'GET', $query);
}

/** What creator_info answers for an audited app. */
function ttCreator(array $options = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'], bool $commentsOff = false): array
{
    return ['data' => [
        'creator_nickname' => 'Ada',
        'privacy_level_options' => $options,
        'comment_disabled' => $commentsOff,
        'duet_disabled' => true,
        'stitch_disabled' => false,
    ], 'error' => ['code' => 'ok', 'message' => '']];
}

function ttTokens(string $access = 'access-new', string $refresh = 'refresh-new'): array
{
    return [
        'access_token' => $access,
        'expires_in' => 86400,
        'open_id' => 'open-1',
        'refresh_token' => $refresh,
        'refresh_expires_in' => 31536000,
        'scope' => 'user.info.basic,video.publish',
        'token_type' => 'Bearer',
    ];
}

it('has the key and label the rest of the package uses', function () {
    expect(tiktok()->key())->toBe('tiktok')
        ->and(tiktok()->label())->toBe('TikTok')
        ->and(tiktok()->isConnected())->toBeFalse();
});

it('sends the browser to the TikTok authorization page with the client key and scopes', function () {
    $url = tiktok()->beginConnection();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(str_starts_with($url, 'https://www.tiktok.com/v2/auth/authorize/?'))->toBeTrue()
        ->and($query)->toMatchArray([
            'client_key' => 'tt-key',
            'response_type' => 'code',
            'scope' => 'user.info.basic,video.publish',
            'state' => session('social-autopost.oauth.tiktok'),
            'redirect_uri' => url('/social/tiktok/callback'),
        ]);
});

it('refuses to start without the client key and secret', function () {
    config()->set('social-autopost.networks.tiktok.client_key', null);

    expect(fn () => tiktok()->beginConnection())->toThrow(NotConfigured::class);
});

it('exchanges the code, stores the tokens and reads the account', function () {
    CarbonImmutable::setTestNow('2026-10-12 12:00:00');
    Http::fake([
        TT_TOKEN => Http::response(ttTokens('access-1', 'refresh-1')),
        TT_USER => Http::response(['data' => ['user' => ['open_id' => 'open-1', 'display_name' => 'Ada', 'avatar_url' => 'https://p16/a.jpg']], 'error' => ['code' => 'ok']]),
    ]);

    $connection = tiktok()->completeConnection(ttCallback(['code' => 'auth-code', 'state' => 'expected-state']));

    expect($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada')
        ->and($connection->scopes)->toBe(['user.info.basic', 'video.publish'])
        // The connection lasts as long as the refresh token.
        ->and($connection->expiresAt?->toIso8601String())->toBe('2027-10-12T12:00:00+00:00');

    $stored = app(ConnectionStore::class)->get('tiktok');
    expect($stored['access_token'])->toBe('access-1')
        ->and($stored['refresh_token'])->toBe('refresh-1')
        ->and($stored['open_id'])->toBe('open-1')
        ->and($stored['expires_at'])->toBe('2026-10-13T12:00:00+00:00');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_TOKEN
        && $request['grant_type'] === 'authorization_code'
        && $request['client_key'] === 'tt-key'
        && $request['code'] === 'auth-code');

    CarbonImmutable::setTestNow();
});

it('keeps the tokens encrypted at rest', function () {
    ttStore('access-secret', 'refresh-secret');

    $payload = (string) SocialConnection::query()->where('network', 'tiktok')->value('payload');

    expect($payload)->not->toContain('access-secret')->not->toContain('refresh-secret');
});

it('reports a refused code as exchange_failed, even when TikTok answers 200', function () {
    Http::fake([TT_TOKEN => Http::response(['error' => 'invalid_grant', 'error_description' => 'Authorization code is expired.'])]);

    try {
        tiktok()->completeConnection(ttCallback(['code' => 'old', 'state' => 'expected-state']));
        $this->fail('Expected a refused code to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('exchange_failed')
            ->and($e->getMessage())->toBe('TikTok rejected the request: Authorization code is expired.');
    }

    expect(tiktok()->isConnected())->toBeFalse();
});

it('reports a cancelled login, a forged state and a missing code', function (array $query, string $state) {
    try {
        tiktok()->completeConnection(ttCallback($query));
        $this->fail('Expected the callback to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe($state);
    }
})->with([
    'cancelled' => [['error' => 'access_denied'], 'cancelled'],
    'forged' => [['code' => 'c', 'state' => 'forged'], 'invalid_state'],
    'no code' => [['state' => 'expected-state'], 'missing_code'],
]);

it('describes the account from the user info and caches it', function () {
    ttStore();
    Http::fake([TT_USER => Http::response(['data' => ['user' => ['display_name' => 'Ada L.', 'avatar_url' => 'https://p16/new.jpg']], 'error' => ['code' => 'ok']])]);

    $connection = tiktok()->connection();
    tiktok()->connection();

    expect($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada L.')
        ->and($connection->daysLeft())->toBeGreaterThanOrEqual(299);
    Http::assertSentCount(1);
});

it('refreshes an expired access token before asking for the account, and stores the new pair', function () {
    ttStore('access-old', 'refresh-old', CarbonImmutable::now()->subHour());
    Http::fake([
        TT_TOKEN => Http::response(ttTokens()),
        TT_USER => Http::response(['data' => ['user' => ['display_name' => 'Ada']], 'error' => ['code' => 'ok']]),
    ]);

    expect(tiktok()->connection()->status)->toBe('active');

    $stored = app(ConnectionStore::class)->get('tiktok');
    expect($stored['access_token'])->toBe('access-new')
        ->and($stored['refresh_token'])->toBe('refresh-new');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_TOKEN
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-old');
    Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'https://open.tiktokapis.com/v2/user/info/')
        && $request->hasHeader('Authorization', 'Bearer access-new'));
});

it('reports expired when TikTok refuses both the token and the refresh', function () {
    ttStore();
    Http::fake([
        TT_USER => Http::response(['data' => [], 'error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid.']], 401),
        TT_TOKEN => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid.']),
    ]);

    expect(tiktok()->connection()->status)->toBe('expired');
});

it('posts a video with the commentary as the caption, following the creator\'s settings', function () {
    ttStore();
    Http::fake([
        TT_CREATOR => Http::response(ttCreator()),
        TT_VIDEO => Http::response(['data' => ['publish_id' => 'v_pub_1'], 'error' => ['code' => 'ok']]),
    ]);

    $id = tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'Title')
        ->image('https://cdn.example.com/a.jpg')
        ->video('https://cdn.example.com/a.mp4')
        ->commentary('New: {title} {url}'));

    expect($id)->toBe('v_pub_1');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_VIDEO
        && $request['post_info']['title'] === 'New: Title https://example.com/a'
        && $request['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE'
        && $request['post_info']['disable_comment'] === false
        && $request['post_info']['disable_duet'] === true
        && $request['source_info'] === ['source' => 'PULL_FROM_URL', 'video_url' => 'https://cdn.example.com/a.mp4']
        && $request->hasHeader('Authorization', 'Bearer access-1'));
    Http::assertNotSent(fn (ClientRequest $request) => $request->url() === TT_CONTENT);
});

it('posts an image as a photo post with the title and a description', function () {
    ttStore();
    Http::fake([
        TT_CREATOR => Http::response(ttCreator(commentsOff: true)),
        TT_CONTENT => Http::response(['data' => ['publish_id' => 'p_pub_1'], 'error' => ['code' => 'ok']]),
    ]);

    $id = tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', str_repeat('t', 120))
        ->image('https://cdn.example.com/a.jpg'));

    expect($id)->toBe('p_pub_1');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_CONTENT
        && $request['media_type'] === 'PHOTO'
        && $request['post_mode'] === 'DIRECT_POST'
        && mb_strlen($request['post_info']['title']) === 90
        && $request['post_info']['disable_comment'] === true
        && $request['source_info']['photo_images'] === ['https://cdn.example.com/a.jpg']);
});

it('refuses a privacy level the creator may not use, naming the allowed ones', function () {
    ttStore();
    Http::fake([TT_CREATOR => Http::response(ttCreator(['SELF_ONLY']))]);

    try {
        tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4'));
        $this->fail('Expected a disallowed privacy level to throw.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toContain('only SELF_ONLY')
            ->and($e->isRetryable())->toBeFalse();
    }

    Http::assertNotSent(fn (ClientRequest $request) => $request->url() === TT_VIDEO);
});

it('posts privately when configured to', function () {
    config()->set('social-autopost.networks.tiktok.privacy_level', 'SELF_ONLY');
    ttStore();
    Http::fake([
        TT_CREATOR => Http::response(ttCreator(['SELF_ONLY'])),
        TT_VIDEO => Http::response(['data' => ['publish_id' => 'v1'], 'error' => ['code' => 'ok']]),
    ]);

    tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4'));

    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_VIDEO
        && $request['post_info']['privacy_level'] === 'SELF_ONLY');
});

it('refuses a post without media before calling TikTok', function () {
    ttStore();
    Http::fake();

    expect(fn () => tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')))
        ->toThrow(RequestFailed::class, 'TikTok posts need a video or an image');

    Http::assertNothingSent();
});

it('refuses to publish when not connected', function () {
    Http::fake();

    expect(fn () => tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4')))
        ->toThrow(NotConnected::class);
});

it('refreshes once and retries when TikTok refuses the token on publish', function () {
    ttStore('access-old', 'refresh-old');
    Http::fake([
        TT_CREATOR => Http::sequence()
            ->push(['error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid.']], 401)
            ->push(ttCreator()),
        TT_TOKEN => Http::response(ttTokens()),
        TT_VIDEO => Http::response(['data' => ['publish_id' => 'v1'], 'error' => ['code' => 'ok']]),
    ]);

    expect(tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4')))->toBe('v1')
        ->and(app(ConnectionStore::class)->get('tiktok')['access_token'])->toBe('access-new');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_VIDEO
        && $request->hasHeader('Authorization', 'Bearer access-new'));
});

it('marks the connection expired when the refresh is refused too', function () {
    ttStore('access-old', 'refresh-old');
    Http::fake([
        TT_CREATOR => Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'invalid']], 401),
        TT_TOKEN => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4')))
        ->toThrow(RequestFailed::class);

    expect(Cache::get('social-autopost:status:tiktok:'.sha1('access-old'))['status'] ?? null)->toBe('expired');
});

it('treats a rate limit, a server error and no answer as retryable', function (Closure $fake) {
    ttStore();
    Http::fake([TT_CREATOR => $fake]);

    try {
        tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4'));
        $this->fail('Expected the publish to throw.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeTrue();
    }
})->with([
    'rate limited' => fn () => fn () => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429),
    'server error' => fn () => fn () => Http::response(['error' => ['code' => 'internal_error', 'message' => 'Oops']], 500),
    'unreachable' => fn () => fn () => throw new ConnectionException('timed out'),
]);

it('reports TikTok\'s reason when it refuses the post', function () {
    ttStore();
    Http::fake([
        TT_CREATOR => Http::response(ttCreator()),
        TT_VIDEO => Http::response(['error' => ['code' => 'url_ownership_unverified', 'message' => 'Please verify the ownership of the URL prefix.']], 403),
    ]);

    try {
        tiktok()->publish(ttPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4'));
        $this->fail('Expected a refused post to throw.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toBe('TikTok rejected the request: Please verify the ownership of the URL prefix.')
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('revokes the token at TikTok and forgets the connection', function () {
    ttStore();
    Http::fake([TT_REVOKE => Http::response([])]);

    expect(tiktok()->disconnect())->toBeTrue()
        ->and(tiktok()->isConnected())->toBeFalse();

    Http::assertSent(fn (ClientRequest $request) => $request->url() === TT_REVOKE
        && $request['token'] === 'access-1'
        && $request['client_secret'] === 'tt-secret');
});

it('forgets the connection even when TikTok refuses the revocation', function () {
    ttStore();
    Http::fake([TT_REVOKE => Http::response(['error' => 'invalid_request', 'error_description' => 'nope'], 400)]);

    expect(tiktok()->disconnect())->toBeFalse()
        ->and(tiktok()->isConnected())->toBeFalse();
});
