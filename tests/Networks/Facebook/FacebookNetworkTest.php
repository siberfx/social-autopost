<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Models\SocialConnection;
use Siberfx\SocialAutopost\Networks\Facebook\FacebookNetwork;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const FB_DEBUG = 'https://graph.facebook.com/v25.0/debug_token*';
const FB_PAGE = 'https://graph.facebook.com/v25.0/1234567?*';
const FB_PAGE_FEED = 'https://graph.facebook.com/v25.0/1234567/feed';
const FB_PERMISSIONS = 'https://graph.facebook.com/v25.0/me/permissions';

beforeEach(function () {
    config()->set('social-autopost.networks.facebook.app_id', 'app-id');
    config()->set('social-autopost.networks.facebook.app_secret', 'app-secret');
    config()->set('social-autopost.networks.facebook.page_id', null);
});

function network(): FacebookNetwork
{
    return app(FacebookNetwork::class);
}

/** Store a Facebook connection the way a completed login would. */
function storeFacebook(string $token = 'page-token', ?string $userToken = 'user-token'): void
{
    app(ConnectionStore::class)->put('facebook', [
        'access_token' => $token,
        'page_id' => '1234567',
        'name' => 'Stored Name',
        'user_access_token' => $userToken,
        'connected_at' => CarbonImmutable::now()->subDays(10)->toIso8601String(),
    ]);
}

function activePage(): void
{
    Http::fake([
        FB_DEBUG => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]),
        FB_PAGE => Http::response(['name' => 'Siberfx Page', 'picture' => ['data' => ['url' => 'https://pic/p.jpg']]]),
    ]);
}

function publishedPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => 'published']);
}

/** A callback request the way Facebook sends it back, with the state the driver stored in the session. */
function callback(array $query, ?string $state = 'expected-state'): HttpRequest
{
    if ($state !== null) {
        session()->put('social-autopost.oauth.facebook', $state);
    }

    return HttpRequest::create('/social/facebook/callback', 'GET', $query);
}

it('has the key and label the other parts of the package use', function () {
    expect(network()->key())->toBe('facebook')
        ->and(network()->label())->toBe('Facebook');
});

it('reports disconnected without asking Facebook', function () {
    Http::fake();

    expect(network()->isConnected())->toBeFalse()
        ->and(network()->connection()->connected)->toBeFalse();

    Http::assertNothingSent();
});

it('stores the state in the session and sends the browser to the Login dialog', function () {
    $url = network()->beginConnection();

    $state = session()->get('social-autopost.oauth.facebook');

    expect($state)->toBeString()->toHaveLength(40)
        ->and($url)->toContain('dialog/oauth')
        ->and($url)->toContain('state='.$state);
});

it('completes the connection, stores the Page token and returns the Page name', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '1234567', 'name' => 'Siberfx Page', 'access_token' => 'page-token'],
        ]]),
    ]);

    $connection = network()->completeConnection(callback(['code' => 'auth-code', 'state' => 'expected-state']));

    expect($connection->network)->toBe('facebook')
        ->and($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Siberfx Page')
        ->and($connection->expiresAt)->toBeNull()
        ->and(network()->isConnected())->toBeTrue()
        ->and(session()->has('social-autopost.oauth.facebook'))->toBeFalse();

    $stored = app(ConnectionStore::class)->get('facebook');
    expect($stored['access_token'])->toBe('page-token')
        ->and($stored['user_access_token'])->toBe('long-user');
});

it('keeps the tokens encrypted at rest', function () {
    storeFacebook('page-token-secret', 'user-token-secret');

    $payload = (string) SocialConnection::query()->where('network', 'facebook')->value('payload');

    expect($payload)->not->toContain('page-token-secret')
        ->and($payload)->not->toContain('user-token-secret');
});

it('reports a cancelled login on Facebook as cancelled', function () {
    try {
        network()->completeConnection(callback(['error' => 'access_denied', 'error_reason' => 'user_denied'], 'expected-state'));
        $this->fail('Expected a cancelled login to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('cancelled');
    }
});

it('reports another login error with Facebook\'s reason', function () {
    try {
        network()->completeConnection(callback(['error' => 'server_error', 'error_description' => 'Try later.'], 'expected-state'));
        $this->fail('Expected a login error to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('error')
            ->and($e->getMessage())->toBe('Facebook: Try later.');
    }
});

it('refuses a callback whose state does not match the session', function () {
    try {
        network()->completeConnection(callback(['code' => 'c', 'state' => 'forged'], 'expected-state'));
        $this->fail('Expected a mismatched state to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('invalid_state');
    }
});

it('refuses a callback with no state in the session', function () {
    try {
        network()->completeConnection(callback(['code' => 'c', 'state' => 'anything'], null));
        $this->fail('Expected a missing session state to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('invalid_state');
    }
});

it('refuses a callback without a code', function () {
    try {
        network()->completeConnection(callback(['state' => 'expected-state'], 'expected-state'));
        $this->fail('Expected a missing code to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('missing_code');
    }
});

it('reports a failed code exchange as exchange_failed with Facebook\'s reason', function () {
    Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::response(['error' => ['message' => 'Invalid code']], 400)]);

    try {
        network()->completeConnection(callback(['code' => 'bad', 'state' => 'expected-state'], 'expected-state'));
        $this->fail('Expected a failed exchange to throw.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('exchange_failed')
            ->and($e->getMessage())->toBe('Facebook rejected the request: Invalid code')
            ->and($e->getPrevious())->toBeInstanceOf(RequestFailed::class);
    }

    expect(network()->isConnected())->toBeFalse();
});

it('describes an active Page connection from debug_token and the Page profile', function () {
    storeFacebook();
    activePage();

    $connection = network()->connection();

    expect($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Siberfx Page')
        ->and($connection->picture)->toBe('https://pic/p.jpg')
        ->and($connection->expiresAt)->toBeNull()
        ->and($connection->connectedAt)->not->toBeNull();
});

it('reports the expiry from debug_token when Facebook sets one', function () {
    storeFacebook();
    $expires = CarbonImmutable::now()->addDays(30)->getTimestamp();
    Http::fake([
        FB_DEBUG => Http::response(['data' => ['is_valid' => true, 'expires_at' => $expires]]),
        FB_PAGE => Http::response(['name' => 'Siberfx Page']),
    ]);

    expect(network()->connection()->expiresAt?->getTimestamp())->toBe($expires);
});

it('says unknown with the stored name when debug_token cannot be read', function () {
    storeFacebook();
    Http::fake([FB_DEBUG => Http::response(['error' => ['message' => 'down']], 500)]);

    $connection = network()->connection();

    expect($connection->status)->toBe('unknown')
        ->and($connection->name)->toBe('Stored Name');
});

it('reports an expired token without asking for the profile', function () {
    storeFacebook();
    Http::fake([FB_DEBUG => Http::response(['data' => ['is_valid' => false]])]);

    expect(network()->connection()->status)->toBe('expired');
    Http::assertSentCount(1);
});

it('caches the status per token for the configured time', function () {
    storeFacebook();
    activePage();

    network()->connection();
    network()->connection();

    Http::assertSentCount(2);
    expect(Cache::has('social-autopost:status:facebook:'.sha1('page-token')))->toBeTrue();
});

it('publishes the commentary and the link to the Page feed and returns the post id', function () {
    storeFacebook();
    Http::fake([FB_PAGE_FEED => Http::response(['id' => '1234567_42'])]);

    $id = network()->publish(publishedPost(), LinkPost::make('https://example.com/x', 'Title')->commentary('New: {title}'));

    expect($id)->toBe('1234567_42');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === FB_PAGE_FEED
        && $request['message'] === 'New: Title'
        && $request['link'] === 'https://example.com/x'
        && $request->hasHeader('Authorization', 'Bearer page-token'));
});

it('returns null when Facebook accepts the post without an id', function () {
    storeFacebook();
    Http::fake([FB_PAGE_FEED => Http::response([], 200)]);

    expect(network()->publish(publishedPost(), LinkPost::make('https://example.com/x', 'X')))->toBeNull();
});

it('refuses to publish when not connected', function () {
    Http::fake();

    expect(fn () => network()->publish(publishedPost(), LinkPost::make('https://example.com/x', 'X')))
        ->toThrow(NotConnected::class);

    Http::assertNothingSent();
});

it('marks the connection expired when Facebook refuses the token on publish', function () {
    storeFacebook();
    Http::fake([
        FB_PAGE_FEED => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400),
        FB_DEBUG => Http::response(['data' => ['is_valid' => false]]),
    ]);

    expect(fn () => network()->publish(publishedPost(), LinkPost::make('https://example.com/x', 'X')))
        ->toThrow(RequestFailed::class);

    expect(Cache::get('social-autopost:status:facebook:'.sha1('page-token'))['status'] ?? null)->toBe('expired');
});

it('leaves a 5xx publish retryable', function () {
    storeFacebook();
    Http::fake([FB_PAGE_FEED => Http::response(['error' => ['message' => 'Oops']], 503)]);

    try {
        network()->publish(publishedPost(), LinkPost::make('https://example.com/x', 'X'));
        $this->fail('Expected a 503 to throw.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

it('removes the app permissions with the stored user token and forgets the connection', function () {
    storeFacebook('page-token', 'user-token');
    Http::fake([FB_PERMISSIONS => Http::response(['success' => true])]);

    expect(network()->disconnect())->toBeTrue()
        ->and(network()->isConnected())->toBeFalse();

    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer user-token'));
});

it('forgets the connection without a user token but reports it as not revoked', function () {
    storeFacebook('page-token', null);
    Http::fake();

    expect(network()->disconnect())->toBeFalse()
        ->and(network()->isConnected())->toBeFalse();

    Http::assertNothingSent();
});

it('forgets the connection even when Facebook refuses the revocation', function () {
    storeFacebook('page-token', 'user-token');
    Http::fake([FB_PERMISSIONS => Http::response(['error' => ['message' => 'nope']], 400)]);

    expect(network()->disconnect())->toBeFalse()
        ->and(network()->isConnected())->toBeFalse();
});

it('reports the connection as disconnected once disconnected, even with a cached status', function () {
    storeFacebook();
    activePage();
    network()->connection();

    network()->disconnect();

    expect(network()->connection()->connected)->toBeFalse();
});

it('posts the photo with the commentary when the post carries an image', function () {
    storeFacebook();
    Http::fake(['https://graph.facebook.com/v25.0/1234567/photos' => Http::response(['id' => '99', 'post_id' => '1234567_99'])]);

    $post = LinkPost::make('https://example.com/a', 'Title')->image('https://cdn.example.com/c.jpg');

    expect(network()->publish(publishedPost(), $post))->toBe('1234567_99');

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/photos')
        && $request['url'] === 'https://cdn.example.com/c.jpg'
        && str_contains((string) $request['message'], 'https://example.com/a'));
});

it('posts the video over the photo, with the commentary as its description', function () {
    storeFacebook();
    Http::fake(['https://graph.facebook.com/v25.0/1234567/videos' => Http::response(['id' => '77'])]);

    $post = LinkPost::make('https://example.com/a', 'Title')
        ->image('https://cdn.example.com/c.jpg')
        ->video('https://cdn.example.com/v.mp4');

    expect(network()->publish(publishedPost(), $post))->toBe('77');

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/videos')
        && $request['file_url'] === 'https://cdn.example.com/v.mp4');
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/photos') || str_contains($request->url(), '/feed'));
});
