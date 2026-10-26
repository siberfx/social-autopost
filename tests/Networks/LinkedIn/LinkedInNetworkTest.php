<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\LinkedIn\LinkedInNetwork;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'social-autopost.networks.linkedin.client_id' => 'client-id',
        'social-autopost.networks.linkedin.client_secret' => 'client-secret',
    ]);
});

/** The stored array a completed LinkedIn OAuth flow leaves behind. */
function storeLinkedIn(string $token = 'member-token'): void
{
    app(ConnectionStore::class)->put('linkedin', [
        'access_token' => $token,
        'author_urn' => 'urn:li:person:abc',
        'name' => 'Stored Name',
        'email' => null,
        'picture' => null,
        'scopes' => ['openid', 'w_member_social'],
        'expires_at' => CarbonImmutable::now()->addDays(50)->toIso8601String(),
        'connected_at' => CarbonImmutable::now()->subDays(10)->toIso8601String(),
    ]);
}

function linkedInNetwork(): LinkedInNetwork
{
    return app(LinkedInNetwork::class);
}

function linkedInPublishedPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => 'published']);
}

/** A cache store like Laravel 13's defaults: values are serialized and no classes may be unserialized. */
function linkedInSerializingCache(): void
{
    config([
        'cache.serializable_classes' => false,
        'cache.stores.serializing' => ['driver' => 'array', 'serialize' => true],
        'cache.default' => 'serializing',
    ]);
}

function linkedInActiveFake(): array
{
    return [
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response([
            'active' => true, 'status' => 'active', 'created_at' => 1790000000, 'expires_at' => 1795184000,
            'scope' => 'openid,profile,email,w_member_social',
        ]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']),
    ];
}

it('reports not connected without calling LinkedIn', function () {
    expect(linkedInNetwork()->isConnected())->toBeFalse()
        ->and(linkedInNetwork()->connection()->connected)->toBeFalse();

    Http::assertNothingSent();
});

it('describes an active connection from introspection and userinfo', function () {
    storeLinkedIn();
    Http::fake(linkedInActiveFake());

    $connection = linkedInNetwork()->connection();

    expect($connection->network)->toBe('linkedin')
        ->and($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and($connection->scopes)->toBe(['openid', 'profile', 'email', 'w_member_social'])
        ->and($connection->expiresAt?->getTimestamp())->toBe(1795184000)
        ->and($connection->toArray())->not->toHaveKey('access_token');
});

it('caches the status per token', function () {
    storeLinkedIn();
    Http::fake(linkedInActiveFake());

    linkedInNetwork()->connection();
    linkedInNetwork()->connection();

    Http::assertSentCount(2);
});

it('caches the status as plain data, so it survives a store that refuses to unserialize classes', function () {
    linkedInSerializingCache();
    storeLinkedIn();
    Http::fake(linkedInActiveFake());

    $first = linkedInNetwork()->connection();
    $second = linkedInNetwork()->connection();

    expect($first)->toBeInstanceOf(Connection::class)
        ->and($second)->toBeInstanceOf(Connection::class)
        ->and($second->toArray())->toBe($first->toArray())
        ->and($second->expiresAt?->getTimestamp())->toBe(1795184000);
    Http::assertSentCount(2);
});

it('treats a cached value that is not plain data as a miss', function () {
    linkedInSerializingCache();
    storeLinkedIn();
    Http::fake(linkedInActiveFake());
    // What an older release cached: an object, which comes back as __PHP_Incomplete_Class.
    Cache::put('social-autopost:status:linkedin:'.hash('sha256', 'member-token'), Connection::disconnected('linkedin'), 300);

    expect(linkedInNetwork()->connection()->status)->toBe('active');
});

it('reports an expired token without asking for the profile', function () {
    storeLinkedIn();
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => false, 'status' => 'expired']),
        'https://api.linkedin.com/v2/userinfo' => Http::response([], 401),
    ]);

    expect(linkedInNetwork()->connection()->status)->toBe('expired')
        ->and(linkedInNetwork()->connection()->name)->toBe('Stored Name');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'userinfo'));
});

it('says unknown with the stored profile when LinkedIn is unreachable', function () {
    storeLinkedIn();
    Http::fake(['*' => Http::response('down', 503)]);

    $connection = linkedInNetwork()->connection();

    expect($connection->status)->toBe('unknown')
        ->and($connection->name)->toBe('Stored Name')
        ->and($connection->daysLeft())->toBe(49);
});

it('marks the cached status expired when LinkedIn rejects the token on publish', function () {
    storeLinkedIn();
    Http::fake(array_merge(linkedInActiveFake(), [
        'https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Invalid access token'], 401),
    ]));
    linkedInNetwork()->connection();

    expect(fn () => linkedInNetwork()->publish(linkedInPublishedPost(), LinkPost::make('https://example.test/p/1', 'Hello')))
        ->toThrow(RequestFailed::class);

    $connection = linkedInNetwork()->connection();
    expect($connection->status)->toBe('expired')
        ->and($connection->name)->toBe('Ada Lovelace');
});

it('marks the cached status expired on a serializing store when LinkedIn rejects the token', function () {
    linkedInSerializingCache();
    storeLinkedIn();
    Http::fake(array_merge(linkedInActiveFake(), [
        'https://api.linkedin.com/rest/posts' => Http::response(['message' => 'Invalid access token'], 401),
    ]));
    linkedInNetwork()->connection();

    expect(fn () => linkedInNetwork()->publish(linkedInPublishedPost(), LinkPost::make('https://example.test/p/1', 'Hello')))
        ->toThrow(RequestFailed::class);

    $connection = linkedInNetwork()->connection();
    expect($connection)->toBeInstanceOf(Connection::class)
        ->and($connection->status)->toBe('expired')
        ->and($connection->name)->toBe('Ada Lovelace');
});

it('publishes the post as the stored author and returns LinkedIn\'s urn', function () {
    storeLinkedIn('member-token');
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:77'])]);

    $urn = linkedInNetwork()->publish(linkedInPublishedPost(), LinkPost::make('https://example.test/p/1', 'Hello [world]'));

    expect($urn)->toBe('urn:li:share:77');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer member-token')
        && $request['author'] === 'urn:li:person:abc'
        && $request['commentary'] === "Hello \[world\]\n\nhttps://example.test/p/1");
});

it('publishes without a urn when LinkedIn returns no id', function () {
    storeLinkedIn();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201)]);

    expect(linkedInNetwork()->publish(linkedInPublishedPost(), LinkPost::make('https://example.test/p/1', 'Hello')))->toBeNull();
});

it('refuses to publish when not connected', function () {
    linkedInNetwork()->publish(linkedInPublishedPost(), LinkPost::make('https://example.test/p/1', 'Hello'));
})->throws(NotConnected::class);

it('starts the OAuth flow with a state kept in the session', function () {
    $url = linkedInNetwork()->beginConnection();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://www.linkedin.com/oauth/v2/authorization?')
        ->and($query['state'])->toBeString()->toHaveLength(40)
        ->and(session()->get('social-autopost.oauth.linkedin'))->toBe($query['state']);
});

it('refuses to start the OAuth flow without credentials', function () {
    config(['social-autopost.networks.linkedin.client_secret' => null]);

    linkedInNetwork()->beginConnection();
})->throws(NotConfigured::class);

it('completes the connection from a matching state and stores the connection', function () {
    CarbonImmutable::setTestNow('2026-10-02 12:00:00');
    session()->put('social-autopost.oauth.linkedin', 'state-1');
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'new-token', 'expires_in' => 5184000]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'xyz', 'name' => 'Grace Hopper']),
    ]);

    $connection = linkedInNetwork()->completeConnection(Request::create('/cb', 'GET', ['state' => 'state-1', 'code' => 'code']));

    expect($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Grace Hopper')
        ->and($connection->network)->toBe('linkedin')
        ->and(session()->has('social-autopost.oauth.linkedin'))->toBeFalse()
        ->and(app(ConnectionStore::class)->get('linkedin')['access_token'])->toBe('new-token')
        ->and(app(ConnectionStore::class)->get('linkedin')['author_urn'])->toBe('urn:li:person:xyz');

    CarbonImmutable::setTestNow();
});

it('refuses a callback whose state does not match or is missing', function (?string $expected, ?string $given) {
    if ($expected !== null) {
        session()->put('social-autopost.oauth.linkedin', $expected);
    }
    $query = $given === null ? [] : ['state' => $given, 'code' => 'c'];

    try {
        linkedInNetwork()->completeConnection(Request::create('/cb', 'GET', $query));
        $this->fail('Expected ConnectionFailed');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('invalid_state');
    }
})->with([
    'no state in session' => [null, 'state-1'],
    'different state' => ['state-1', 'state-2'],
    'no state in query' => ['state-1', null],
]);

it('maps the callback errors to their states', function (array $query, string $state, ?string $reason) {
    session()->put('social-autopost.oauth.linkedin', 'state-1');

    try {
        linkedInNetwork()->completeConnection(Request::create('/cb', 'GET', ['state' => 'state-1'] + $query));
        $this->fail('Expected ConnectionFailed');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe($state)
            ->and($reason === null || str_contains($e->getMessage(), $reason))->toBeTrue();
    }
})->with([
    'user cancelled' => [['error' => 'user_cancelled_authorize'], 'cancelled', null],
    'LinkedIn error with its description' => [['error' => 'unauthorized_scope_error', 'error_description' => 'Scope "profile" is not authorized'], 'error', 'Scope "profile" is not authorized'],
    'no code' => [[], 'missing_code', null],
]);

it('reports a refused code as exchange_failed and stores nothing', function () {
    session()->put('social-autopost.oauth.linkedin', 'state-1');
    Http::fake(['https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_code'], 400)]);

    try {
        linkedInNetwork()->completeConnection(Request::create('/cb', 'GET', ['state' => 'state-1', 'code' => 'bad']));
        $this->fail('Expected ConnectionFailed');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('exchange_failed');
    }

    expect(app(ConnectionStore::class)->get('linkedin'))->toBeNull();
});

it('disconnects, revoking at LinkedIn', function (int $revokeStatus, bool $revoked) {
    storeLinkedIn();
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', $revokeStatus)]);

    expect(linkedInNetwork()->disconnect())->toBe($revoked)
        ->and(linkedInNetwork()->isConnected())->toBeFalse();
})->with([
    'revoked' => [200, true],
    'refused, still forgotten locally' => [500, false],
]);

it('forgets a not-connected network without calling LinkedIn', function () {
    expect(linkedInNetwork()->disconnect())->toBeTrue();

    Http::assertNothingSent();
});

it('clears the cached status on disconnect', function () {
    storeLinkedIn();
    Http::fake(array_merge(linkedInActiveFake(), ['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200)]));
    linkedInNetwork()->connection();

    linkedInNetwork()->disconnect();

    expect(Cache::has('social-autopost:status:linkedin:'.hash('sha256', 'member-token')))->toBeFalse();
});

it('uploads the image and publishes an image post when the post carries one', function () {
    storeLinkedIn();
    Http::fake([
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.linkedin.com/u/1', 'image' => 'urn:li:image:img1'],
        ]),
        'https://cdn.example.com/cover.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'https://upload.linkedin.com/u/1' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:9']),
    ]);

    $post = LinkPost::make('https://example.com/a', 'Title')->image('https://cdn.example.com/cover.jpg');

    expect(linkedInNetwork()->publish(linkedInPublishedPost(), $post))->toBe('urn:li:share:9');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://upload.linkedin.com/u/1'
        && $request->body() === 'jpeg-bytes'
        && $request->hasHeader('Content-Type', 'image/jpeg'));
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/rest/posts')
        && $request['content'] === ['media' => ['id' => 'urn:li:image:img1', 'title' => 'Title']]);
});

it('does not post when the image cannot be downloaded, and the failure is not retryable', function () {
    storeLinkedIn();
    Http::fake([
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.linkedin.com/u/1', 'image' => 'urn:li:image:img1'],
        ]),
        'https://cdn.example.com/cover.jpg' => Http::response('gone', 404),
    ]);

    $post = LinkPost::make('https://example.com/a', 'Title')->image('https://cdn.example.com/cover.jpg');

    try {
        linkedInNetwork()->publish(linkedInPublishedPost(), $post);
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeFalse()
            ->and($e->isUnauthorized())->toBeFalse();
    }

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/rest/posts'));
});

it('keeps the link post when the post carries only a video', function () {
    storeLinkedIn();
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:10'])]);

    $post = LinkPost::make('https://example.com/a', 'Title')->video('https://cdn.example.com/clip.mp4');

    expect(linkedInNetwork()->publish(linkedInPublishedPost(), $post))->toBe('urn:li:share:10');

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/rest/posts')
        && isset($request['content']['article']));
});
