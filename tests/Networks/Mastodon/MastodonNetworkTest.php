<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\Mastodon\MastodonAccount;
use Siberfx\SocialAutopost\Networks\Mastodon\MastodonNetwork;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

const MASTODON_VERIFY = 'https://fosstodon.example/api/v1/accounts/verify_credentials';
const MASTODON_STATUSES = 'https://fosstodon.example/api/v1/statuses';

beforeEach(function () {
    config()->set('social-autopost.networks.mastodon.instance', 'https://fosstodon.example');
    config()->set('social-autopost.networks.mastodon.client_id', 'mastodon-client');
    config()->set('social-autopost.networks.mastodon.client_secret', 'mastodon-secret');
    config()->set('social-autopost.networks.mastodon.redirect_uri', null);
});

/** Store a Mastodon connection, as a completed OAuth flow would. */
function mastodonSeed(string $token = 'token-1'): void
{
    app(ConnectionStore::class)->put('mastodon', (new MastodonAccount(
        accessToken: $token,
        username: 'ada@fosstodon.example',
        name: 'Ada',
        picture: 'https://files.example/ada.png',
        scopes: ['write:statuses'],
        connectedAt: CarbonImmutable::parse('2026-10-01T10:00:00Z'),
    ))->toArray());
}

function mastodonProfile(): array
{
    return ['acct' => 'ada@fosstodon.example', 'username' => 'ada', 'display_name' => 'Ada Lovelace', 'avatar' => 'https://files.example/new.png'];
}

it('describes itself and reports connected from the store only', function () {
    $network = app(MastodonNetwork::class);

    expect($network->key())->toBe('mastodon')
        ->and($network->label())->toBe('Mastodon')
        ->and($network->isConnected())->toBeFalse();

    mastodonSeed();

    expect($network->isConnected())->toBeTrue();
    Http::assertNothingSent();
});

it('starts the flow on the instance with the scopes and keeps the state in the session', function () {
    $url = app(MastodonNetwork::class)->beginConnection();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(str_starts_with($url, 'https://fosstodon.example/oauth/authorize?'))->toBeTrue()
        ->and($query['client_id'])->toBe('mastodon-client')
        ->and($query['scope'])->toBe('read:accounts write:statuses write:media')
        ->and($query['state'])->toBe(session('social-autopost.oauth.mastodon'));
});

it('refuses to start the flow without an instance', function () {
    config()->set('social-autopost.networks.mastodon.instance', null);

    app(MastodonNetwork::class)->beginConnection();
})->throws(NotConfigured::class);

it('completes the flow: exchanges the code, reads the account and stores it', function () {
    Http::fake([
        'https://fosstodon.example/oauth/token' => Http::response(['access_token' => 'token-1', 'scope' => 'read:accounts write:statuses']),
        MASTODON_VERIFY => Http::response(mastodonProfile()),
    ]);

    app(MastodonNetwork::class)->beginConnection();
    $state = session('social-autopost.oauth.mastodon');

    $connection = app(MastodonNetwork::class)->completeConnection(Request::create('/callback', 'GET', [
        'code' => 'code-1',
        'state' => $state,
    ]));

    expect($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and(app(ConnectionStore::class)->get('mastodon'))->toMatchArray([
            'access_token' => 'token-1',
            'username' => 'ada@fosstodon.example',
        ])
        ->and(session()->has('social-autopost.oauth.mastodon'))->toBeFalse();
});

it('refuses a callback whose state does not match', function () {
    app(MastodonNetwork::class)->beginConnection();

    try {
        app(MastodonNetwork::class)->completeConnection(Request::create('/callback', 'GET', ['code' => 'c', 'state' => 'forged']));
        $this->fail('Expected ConnectionFailed.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('invalid_state');
    }
});

it('reports the account as active and caches the status per token', function () {
    mastodonSeed();
    Http::fake([MASTODON_VERIFY => Http::response(mastodonProfile())]);

    $first = app(MastodonNetwork::class)->connection();
    $second = app(MastodonNetwork::class)->connection();

    expect($first->status)->toBe('active')
        ->and($first->name)->toBe('Ada Lovelace')
        ->and($second->status)->toBe('active');

    Http::assertSentCount(1);
});

it('reports the connection expired when the instance refuses the token', function () {
    mastodonSeed();
    Http::fake([MASTODON_VERIFY => Http::response(['error' => 'The access token is invalid'], 401)]);

    expect(app(MastodonNetwork::class)->connection()->status)->toBe('expired');
});

it('publishes the composed status with an idempotency key and returns the id', function () {
    mastodonSeed();
    Http::fake([MASTODON_STATUSES => Http::response(['id' => '112233', 'url' => 'https://fosstodon.example/@ada/112233'])]);

    $post = LinkPost::make('https://example.com/a', 'Title')->description('Desc');

    expect(app(MastodonNetwork::class)->publish(new Post, $post))->toBe('112233');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === MASTODON_STATUSES
        && $request['status'] === "Title\n\nDesc\n\nhttps://example.com/a"
        && $request['visibility'] === 'public'
        && $request->hasHeader('Idempotency-Key'));
});

it('uploads the image and attaches it to the status', function () {
    mastodonSeed();
    Http::fake([
        'https://cdn.example.com/cover.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'https://fosstodon.example/api/v2/media' => Http::response(['id' => '555']),
        MASTODON_STATUSES => Http::response(['id' => '112234']),
    ]);

    $post = LinkPost::make('https://example.com/a', 'Title')->image('https://cdn.example.com/cover.jpg');

    expect(app(MastodonNetwork::class)->publish(new Post, $post))->toBe('112234');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === MASTODON_STATUSES
        && $request['media_ids'] === ['555']);
});

it('refuses to publish when not connected', function () {
    app(MastodonNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title'));
})->throws(NotConnected::class);

it('reports a refused status as a RequestFailed with the instance reason', function () {
    mastodonSeed();
    Http::fake([MASTODON_STATUSES => Http::response(['error' => 'Validation failed'], 422)]);

    try {
        app(MastodonNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title'));
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toBe('Mastodon rejected the request: Validation failed')
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('revokes the token at the instance and forgets the connection', function () {
    mastodonSeed();
    Http::fake(['https://fosstodon.example/oauth/revoke' => Http::response([])]);

    expect(app(MastodonNetwork::class)->disconnect())->toBeTrue()
        ->and(app(MastodonNetwork::class)->isConnected())->toBeFalse();

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/oauth/revoke')
        && str_contains($request->body(), 'token=token-1'));
});
