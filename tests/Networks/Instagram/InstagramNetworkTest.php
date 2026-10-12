<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Models\SocialConnection;
use Siberfx\SocialAutopost\Networks\Instagram\InstagramAccount;
use Siberfx\SocialAutopost\Networks\Instagram\InstagramNetwork;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const IG_TOKEN = 'https://api.instagram.com/oauth/access_token';
const IG_LONG = 'https://graph.instagram.com/access_token*';
const IG_REFRESH = 'https://graph.instagram.com/refresh_access_token*';
const IG_ME = 'https://graph.instagram.com/v25.0/me*';
const IG_MEDIA = 'https://graph.instagram.com/v25.0/1784/media';
const IG_CONTAINER = 'https://graph.instagram.com/v25.0/c1*';
const IG_PUBLISH = 'https://graph.instagram.com/v25.0/1784/media_publish';

beforeEach(function () {
    config()->set('social-autopost.networks.instagram.client_id', 'ig-app');
    config()->set('social-autopost.networks.instagram.client_secret', 'ig-secret');
    config()->set('social-autopost.networks.instagram.redirect_uri', null);
    config()->set('social-autopost.networks.instagram.api_version', 'v25.0');
    config()->set('social-autopost.networks.instagram.processing_checks', 3);
    config()->set('social-autopost.networks.instagram.processing_interval', 5);
});

function instagram(): InstagramNetwork
{
    return app(InstagramNetwork::class);
}

/** Store an Instagram connection, as a completed login would. */
function igStore(string $token = 'long-token', ?CarbonImmutable $expiresAt = null): void
{
    app(ConnectionStore::class)->put('instagram', (new InstagramAccount(
        accessToken: $token,
        userId: '1784',
        username: 'siberfx',
        name: 'Siberfx',
        picture: 'https://pic/old.jpg',
        expiresAt: $expiresAt ?? CarbonImmutable::now()->addDays(50),
        connectedAt: CarbonImmutable::now()->subDays(10),
    ))->toArray());
}

function igPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => 'published']);
}

function igCallback(array $query, ?string $state = 'expected-state'): HttpRequest
{
    if ($state !== null) {
        session()->put('social-autopost.oauth.instagram', $state);
    }

    return HttpRequest::create('/social/instagram/callback', 'GET', $query);
}

function igQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('has the key and label the rest of the package uses', function () {
    expect(instagram()->key())->toBe('instagram')
        ->and(instagram()->label())->toBe('Instagram');
});

it('reports disconnected without asking Instagram', function () {
    Http::fake();

    expect(instagram()->isConnected())->toBeFalse()
        ->and(instagram()->connection()->connected)->toBeFalse();

    Http::assertNothingSent();
});

it('sends the browser to the Instagram authorization window with the publishing scopes', function () {
    $url = instagram()->beginConnection();
    $state = session('social-autopost.oauth.instagram');

    expect($state)->toBeString()->toHaveLength(40)
        ->and(str_starts_with($url, 'https://www.instagram.com/oauth/authorize?'))->toBeTrue()
        ->and(igQuery($url))->toMatchArray([
            'client_id' => 'ig-app',
            'response_type' => 'code',
            'scope' => 'instagram_business_basic,instagram_business_content_publish',
            'state' => $state,
            'redirect_uri' => url('/social/instagram/callback'),
        ]);
});

it('refuses to start without the app credentials', function () {
    config()->set('social-autopost.networks.instagram.client_secret', null);

    expect(fn () => instagram()->beginConnection())->toThrow(NotConfigured::class);
});

it('exchanges the code for a long-lived token and stores the account', function () {
    CarbonImmutable::setTestNow('2026-10-12 12:00:00');
    Http::fake([
        IG_TOKEN => Http::response(['access_token' => 'short-token', 'user_id' => 99, 'permissions' => []]),
        IG_LONG => Http::response(['access_token' => 'long-token', 'token_type' => 'bearer', 'expires_in' => 5184000]),
        IG_ME => Http::response(['user_id' => '1784', 'username' => 'siberfx', 'name' => 'Siberfx', 'profile_picture_url' => 'https://pic/p.jpg']),
    ]);

    $connection = instagram()->completeConnection(igCallback(['code' => 'auth-code#_', 'state' => 'expected-state']));

    expect($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('siberfx')
        ->and($connection->expiresAt?->toIso8601String())->toBe('2026-12-11T12:00:00+00:00')
        ->and(session()->has('social-autopost.oauth.instagram'))->toBeFalse();

    $stored = app(ConnectionStore::class)->get('instagram');
    expect($stored['access_token'])->toBe('long-token')
        ->and($stored['user_id'])->toBe('1784');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === IG_TOKEN
        && $request['code'] === 'auth-code'
        && $request['grant_type'] === 'authorization_code'
        && $request['client_secret'] === 'ig-secret');
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'grant_type=ig_exchange_token')
        && igQuery($request->url())['access_token'] === 'short-token');

    CarbonImmutable::setTestNow();
});

it('keeps the token encrypted at rest', function () {
    igStore('long-token-secret');

    expect((string) SocialConnection::query()->where('network', 'instagram')->value('payload'))
        ->not->toContain('long-token-secret');
});

it('reports a cancelled login as cancelled and a forged state as invalid', function () {
    try {
        instagram()->completeConnection(igCallback(['error' => 'access_denied', 'error_reason' => 'user_denied']));
        $this->fail('Expected a cancelled login to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('cancelled');
    }

    try {
        instagram()->completeConnection(igCallback(['code' => 'c', 'state' => 'forged']));
        $this->fail('Expected a forged state to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('invalid_state');
    }
});

it('reports a refused code as exchange_failed with Instagram\'s reason', function () {
    Http::fake([IG_TOKEN => Http::response(['error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Invalid authorization code'], 400)]);

    try {
        instagram()->completeConnection(igCallback(['code' => 'bad', 'state' => 'expected-state']));
        $this->fail('Expected a refused code to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('exchange_failed')
            ->and($e->getMessage())->toBe('Instagram rejected the request: Invalid authorization code');
    }

    expect(instagram()->isConnected())->toBeFalse();
});

it('describes the account from the profile and caches it', function () {
    igStore();
    Http::fake([IG_ME => Http::response(['user_id' => '1784', 'username' => 'siberfx', 'profile_picture_url' => 'https://pic/new.jpg'])]);

    $connection = instagram()->connection();
    instagram()->connection();

    expect($connection->status)->toBe('active')
        ->and($connection->picture)->toBe('https://pic/new.jpg')
        ->and($connection->daysLeft())->toBeGreaterThanOrEqual(49);
    Http::assertSentCount(1);
});

it('reports a refused token as expired', function () {
    igStore();
    Http::fake([IG_ME => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)]);

    expect(instagram()->connection()->status)->toBe('expired');
});

it('refreshes the token once fewer than 30 days remain and stores the new one', function () {
    CarbonImmutable::setTestNow('2026-10-12 12:00:00');
    igStore('old-token', CarbonImmutable::now()->addDays(10));
    Http::fake([
        IG_REFRESH => Http::response(['access_token' => 'new-token', 'expires_in' => 5184000]),
        IG_ME => Http::response(['user_id' => '1784', 'username' => 'siberfx']),
    ]);

    $connection = instagram()->connection();

    expect(app(ConnectionStore::class)->get('instagram')['access_token'])->toBe('new-token')
        ->and($connection->expiresAt?->toIso8601String())->toBe('2026-12-11T12:00:00+00:00');
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'refresh_access_token')
        && igQuery($request->url())['access_token'] === 'old-token');

    CarbonImmutable::setTestNow();
});

it('does not refresh a token with more than 30 days left', function () {
    igStore('long-token', CarbonImmutable::now()->addDays(50));
    Http::fake([IG_ME => Http::response(['user_id' => '1784'])]);

    instagram()->connection();

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'refresh_access_token'));
});

it('keeps the old token when Instagram refuses the refresh', function () {
    igStore('old-token', CarbonImmutable::now()->addDays(5));
    Http::fake([
        IG_REFRESH => Http::response(['error' => ['message' => 'nope']], 400),
        IG_ME => Http::response(['user_id' => '1784']),
    ]);

    expect(instagram()->connection()->status)->toBe('active')
        ->and(app(ConnectionStore::class)->get('instagram')['access_token'])->toBe('old-token');
});

it('publishes an image: creates a container, waits for it, then publishes it', function () {
    igStore();
    Http::fake([
        IG_MEDIA => Http::response(['id' => 'c1']),
        IG_CONTAINER => Http::response(['status_code' => 'FINISHED']),
        IG_PUBLISH => Http::response(['id' => '17900000000000001']),
    ]);

    $id = instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'Title')
        ->image('https://cdn.example.com/a.jpg')
        ->commentary('New: {title} {url}'));

    expect($id)->toBe('17900000000000001');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === IG_MEDIA
        && $request['image_url'] === 'https://cdn.example.com/a.jpg'
        && $request['caption'] === 'New: Title https://example.com/a'
        && ! isset($request['media_type'])
        && $request->hasHeader('Authorization', 'Bearer long-token'));
    Http::assertSent(fn (ClientRequest $request) => $request->url() === IG_PUBLISH && $request['creation_id'] === 'c1');
});

it('publishes a video as a Reel, polling until Instagram has processed it', function () {
    igStore();
    Sleep::fake();
    Http::fake([
        IG_MEDIA => Http::response(['id' => 'c1']),
        IG_CONTAINER => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS'])
            ->push(['status_code' => 'FINISHED']),
        IG_PUBLISH => Http::response(['id' => 'm1']),
    ]);

    $id = instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')
        ->image('https://cdn.example.com/a.jpg')
        ->video('https://cdn.example.com/a.mp4'));

    expect($id)->toBe('m1');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === IG_MEDIA
        && $request['media_type'] === 'REELS'
        && $request['video_url'] === 'https://cdn.example.com/a.mp4'
        && ! isset($request['image_url']));
    Sleep::assertSleptTimes(1);
});

it('cuts the caption to 2,200 characters', function () {
    igStore();
    Http::fake([
        IG_MEDIA => Http::response(['id' => 'c1']),
        IG_CONTAINER => Http::response(['status_code' => 'FINISHED']),
        IG_PUBLISH => Http::response(['id' => 'm1']),
    ]);

    instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')
        ->image('https://cdn.example.com/a.jpg')
        ->commentary(str_repeat('a', 3000)));

    Http::assertSent(fn (ClientRequest $request) => $request->url() === IG_MEDIA && mb_strlen($request['caption']) === 2200);
});

it('refuses a post without media before calling Instagram, and does not retry it', function () {
    igStore();
    Http::fake();

    try {
        instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T'));
        $this->fail('Expected a post without media to throw.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toContain('need an image or a video')
            ->and($e->isRetryable())->toBeFalse();
    }

    Http::assertNothingSent();
});

it('refuses to publish when not connected', function () {
    Http::fake();

    expect(fn () => instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')->image('https://cdn.example.com/a.jpg')))
        ->toThrow(NotConnected::class);
});

it('fails without retrying when Instagram cannot process the media', function () {
    igStore();
    Http::fake([
        IG_MEDIA => Http::response(['id' => 'c1']),
        IG_CONTAINER => Http::response(['status_code' => 'ERROR', 'status' => 'Error: unsupported aspect ratio']),
    ]);

    try {
        instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')->image('https://cdn.example.com/a.png'));
        $this->fail('Expected a failed container to throw.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toBe('Instagram could not process the media: Error: unsupported aspect ratio')
            ->and($e->isRetryable())->toBeFalse();
    }

    Http::assertNotSent(fn (ClientRequest $request) => $request->url() === IG_PUBLISH);
});

it('gives up after the configured checks with a retryable failure, without publishing', function () {
    igStore();
    Sleep::fake();
    Http::fake([
        IG_MEDIA => Http::response(['id' => 'c1']),
        IG_CONTAINER => Http::response(['status_code' => 'IN_PROGRESS']),
    ]);

    try {
        instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')->video('https://cdn.example.com/a.mp4'));
        $this->fail('Expected a slow container to throw.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeTrue();
    }

    Sleep::assertSleptTimes(2);
    Http::assertNotSent(fn (ClientRequest $request) => $request->url() === IG_PUBLISH);
});

it('marks the connection expired when Instagram refuses the token on publish', function () {
    igStore();
    Http::fake([IG_MEDIA => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

    try {
        instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')->image('https://cdn.example.com/a.jpg'));
        $this->fail('Expected a refused token to throw.');
    } catch (RequestFailed $e) {
        expect($e->isUnauthorized())->toBeTrue();
    }

    expect(Cache::get('social-autopost:status:instagram:'.sha1('long-token'))['status'] ?? null)->toBe('expired');
});

it('treats an unreachable Instagram and a rate limit as retryable', function (Closure $fake) {
    igStore();
    Http::fake([IG_MEDIA => $fake]);

    try {
        instagram()->publish(igPost(), LinkPost::make('https://example.com/a', 'T')->image('https://cdn.example.com/a.jpg'));
        $this->fail('Expected the publish to throw.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeTrue();
    }
})->with([
    'unreachable' => fn () => fn () => throw new ConnectionException('timed out'),
    'rate limited' => fn () => fn () => Http::response(['error' => ['message' => 'Too many calls', 'code' => 4]], 429),
]);

it('forgets the connection on disconnect and says Instagram did not revoke it', function () {
    igStore();
    Http::fake();

    expect(instagram()->disconnect())->toBeFalse()
        ->and(instagram()->isConnected())->toBeFalse();

    Http::assertNothingSent();
});
