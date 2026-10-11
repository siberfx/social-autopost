<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\X\OAuthClient;
use Siberfx\SocialAutopost\Networks\X\XAccount;
use Siberfx\SocialAutopost\Networks\X\XNetwork;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config()->set('social-autopost.networks.x.client_id', 'x-client');
    config()->set('social-autopost.networks.x.client_secret', 'x-secret');
    config()->set('social-autopost.networks.x.redirect_uri', null);
});

/** Store an X connection, as a completed OAuth flow would. */
function xSeedAccount(string $access = 'access-1', ?string $refresh = 'refresh-1', ?string $name = 'Ada'): void
{
    app(ConnectionStore::class)->put('x', (new XAccount(
        accessToken: $access,
        refreshToken: $refresh,
        username: 'ada',
        name: $name,
        picture: 'https://pbs.example/ada.jpg',
        scopes: ['tweet.write'],
        connectedAt: CarbonImmutable::parse('2026-10-01T10:00:00Z'),
    ))->toArray());
}

/** Run the callback and return the ConnectionFailed it throws. */
function xConnectionFailure(Request $request): ConnectionFailed
{
    try {
        app(XNetwork::class)->completeConnection($request);
    } catch (ConnectionFailed $e) {
        return $e;
    }

    throw new LogicException('Expected ConnectionFailed.');
}

/** A profile answer for /2/users/me. */
function xProfile(): array
{
    return ['data' => ['name' => 'Ada Lovelace', 'username' => 'ada', 'profile_image_url' => 'https://pbs.example/new.jpg']];
}

it('describes itself and reports connected from the store only', function () {
    $network = app(XNetwork::class);

    expect($network->key())->toBe('x')
        ->and($network->label())->toBe('X')
        ->and($network->isConnected())->toBeFalse();

    xSeedAccount();

    expect($network->isConnected())->toBeTrue();
    Http::assertNothingSent();
});

it('starts the PKCE flow and keeps the state and verifier in the session', function () {
    $url = app(XNetwork::class)->beginConnection();

    $pending = session('social-autopost.oauth.x');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($pending['state'])->toBe($query['state'])
        ->and($query['code_challenge'])->toBe(OAuthClient::codeChallenge($pending['verifier']))
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(strlen($pending['verifier']))->toBeGreaterThanOrEqual(43);
});

it('refuses to start the flow without a client id', function () {
    config()->set('social-autopost.networks.x.client_id', null);

    app(XNetwork::class)->beginConnection();
})->throws(NotConfigured::class);

it('completes the flow: exchanges the code with the verifier, stores the account and consumes the state', function () {
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'scope' => 'tweet.write']),
        'https://api.x.com/2/users/me*' => Http::response(xProfile()),
    ]);

    app(XNetwork::class)->beginConnection();
    $pending = session('social-autopost.oauth.x');

    $connection = app(XNetwork::class)->completeConnection(Request::create('/callback', 'GET', [
        'code' => 'code-1',
        'state' => $pending['state'],
    ]));

    Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/2/oauth2/token')
        && str_contains($request->body(), 'code_verifier='.$pending['verifier']));

    expect($connection->network)->toBe('x')
        ->and($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and($connection->picture)->toBe('https://pbs.example/new.jpg')
        ->and($connection->scopes)->toBe(['tweet.write'])
        ->and(app(ConnectionStore::class)->get('x'))->toMatchArray([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'username' => 'ada',
        ])
        ->and(session()->has('social-autopost.oauth.x'))->toBeFalse();
});

it('refuses a callback whose state does not match, and consumes the pending state', function () {
    app(XNetwork::class)->beginConnection();

    $failure = xConnectionFailure(Request::create('/callback', 'GET', ['code' => 'c', 'state' => 'forged']));

    expect($failure->state)->toBe('invalid_state')
        ->and(session()->has('social-autopost.oauth.x'))->toBeFalse()
        ->and(app(ConnectionStore::class)->get('x'))->toBeNull();
});

it('refuses a callback with no connection in progress', function () {
    $failure = xConnectionFailure(Request::create('/callback', 'GET', ['code' => 'c', 'state' => 'anything']));

    expect($failure->state)->toBe('invalid_state');
});

it('reports a cancelled sign-in as cancelled', function () {
    app(XNetwork::class)->beginConnection();

    $failure = xConnectionFailure(Request::create('/callback', 'GET', ['error' => 'access_denied', 'state' => 'x']));

    expect($failure->state)->toBe('cancelled');
});

it('reports other errors from X with X\'s description', function () {
    app(XNetwork::class)->beginConnection();

    $failure = xConnectionFailure(Request::create('/callback', 'GET', [
        'error' => 'unauthorized_client',
        'error_description' => 'The client is not allowed.',
        'state' => 'x',
    ]));

    expect($failure->state)->toBe('error')
        ->and($failure->getMessage())->toBe('X refused the connection: The client is not allowed.');
});

it('reports a callback with no authorization code as missing_code', function () {
    app(XNetwork::class)->beginConnection();
    $state = session('social-autopost.oauth.x')['state'];

    $failure = xConnectionFailure(Request::create('/callback', 'GET', ['state' => $state]));

    expect($failure->state)->toBe('missing_code');
});

it('reports a refused code exchange as exchange_failed with the reason', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid code.'], 400)]);

    app(XNetwork::class)->beginConnection();
    $state = session('social-autopost.oauth.x')['state'];

    $failure = xConnectionFailure(Request::create('/callback', 'GET', ['code' => 'stale', 'state' => $state]));

    expect($failure->state)->toBe('exchange_failed')
        ->and($failure->getMessage())->toBe('X rejected the request: Invalid code.')
        ->and(app(ConnectionStore::class)->get('x'))->toBeNull();
});

it('reports disconnected when nothing is stored, without calling X', function () {
    $connection = app(XNetwork::class)->connection();

    expect($connection->connected)->toBeFalse()
        ->and($connection->network)->toBe('x');

    Http::assertNothingSent();
});

it('reports the account as active and caches the status per token', function () {
    xSeedAccount();
    Http::fake(['https://api.x.com/2/users/me*' => Http::response(xProfile())]);

    $first = app(XNetwork::class)->connection();
    $second = app(XNetwork::class)->connection();

    expect($first->status)->toBe('active')
        ->and($first->name)->toBe('Ada Lovelace')
        ->and($second->status)->toBe('active');

    Http::assertSentCount(1);
    expect(Cache::has('social-autopost:status:x:'.sha1('access-1')))->toBeTrue();
});

it('refreshes an expired access token, stores the rotated pair and reports active', function () {
    xSeedAccount();
    Http::fake([
        'https://api.x.com/2/users/me*' => Http::sequence()
            ->push(['detail' => 'Unauthorized'], 401)
            ->push(xProfile(), 200),
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2']),
    ]);

    $connection = app(XNetwork::class)->connection();

    expect($connection->status)->toBe('active')
        ->and(app(ConnectionStore::class)->get('x'))->toMatchArray([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
        ]);
});

it('reports the connection expired when the refresh is refused too', function () {
    xSeedAccount();
    Http::fake([
        'https://api.x.com/2/users/me*' => Http::response(['detail' => 'Unauthorized'], 401),
        'https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $connection = app(XNetwork::class)->connection();

    expect($connection->status)->toBe('expired')
        ->and(app(ConnectionStore::class)->get('x'))->toMatchArray(['access_token' => 'access-1']);
});

it('does not try to refresh an expired token without a refresh token', function () {
    xSeedAccount(refresh: null);
    Http::fake(['https://api.x.com/2/users/me*' => Http::response(['detail' => 'Unauthorized'], 401)]);

    expect(app(XNetwork::class)->connection()->status)->toBe('expired');

    Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/2/oauth2/token'));
});

it('reports unknown with the stored name when X cannot be reached', function () {
    xSeedAccount(name: 'Ada');
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $connection = app(XNetwork::class)->connection();

    expect($connection->status)->toBe('unknown')
        ->and($connection->name)->toBe('Ada');
});

it('publishes the composed text and returns the post id', function () {
    xSeedAccount();
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '555']], 201)]);

    $post = LinkPost::make('https://example.com/a', 'Title')->description('Desc');

    expect(app(XNetwork::class)->publish(new Post, $post))->toBe('555');

    Http::assertSent(fn (OutgoingRequest $request) => $request['text'] === "Title\n\nDesc\n\nhttps://example.com/a"
        && $request->header('Authorization')[0] === 'Bearer access-1');
});

it('refuses to publish when not connected', function () {
    app(XNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title'));
})->throws(NotConnected::class);

it('refreshes once after a 401, stores the rotated pair and posts again', function () {
    xSeedAccount();
    Http::fake([
        'https://api.x.com/2/tweets' => Http::sequence()
            ->push(['detail' => 'Unauthorized'], 401)
            ->push(['data' => ['id' => '556']], 201),
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2']),
    ]);

    expect(app(XNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title')))->toBe('556');

    Http::assertSentCount(3);
    expect(app(ConnectionStore::class)->get('x'))->toMatchArray([
        'access_token' => 'access-2',
        'refresh_token' => 'refresh-2',
    ]);
});

it('marks the connection expired and throws the 401 when the refresh is refused', function () {
    xSeedAccount();
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['detail' => 'Unauthorized'], 401),
        'https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    try {
        app(XNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title'));
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->isUnauthorized())->toBeTrue();
    }

    expect(Cache::get('social-autopost:status:x:'.sha1('access-1')))->toMatchArray(['status' => 'expired']);
});

it('does not refresh for a failure other than 401, and reports it as retryable when it is a server error', function () {
    xSeedAccount();
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['detail' => 'Unavailable'], 503)]);

    try {
        app(XNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title'));
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeTrue();
    }

    Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/2/oauth2/token'));
});

it('revokes the token at X, forgets the connection and its cached status', function () {
    xSeedAccount();
    Cache::put('social-autopost:status:x:'.sha1('access-1'), ['status' => 'active'], 300);
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response(['data' => ['revoked' => true]])]);

    expect(app(XNetwork::class)->disconnect())->toBeTrue()
        ->and(app(XNetwork::class)->isConnected())->toBeFalse()
        ->and(Cache::has('social-autopost:status:x:'.sha1('access-1')))->toBeFalse();

    Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/2/oauth2/revoke')
        && str_contains($request->body(), 'token=access-1'));
});

it('forgets the connection even when X does not confirm the revocation', function () {
    xSeedAccount();
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response(['detail' => 'Unavailable'], 503)]);

    expect(app(XNetwork::class)->disconnect())->toBeFalse()
        ->and(app(XNetwork::class)->isConnected())->toBeFalse();
});

it('disconnects without calling X when nothing is stored', function () {
    expect(app(XNetwork::class)->disconnect())->toBeTrue();

    Http::assertNothingSent();
});
