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
use Siberfx\SocialAutopost\Networks\Bluesky\BlueskyAccount;
use Siberfx\SocialAutopost\Networks\Bluesky\BlueskyNetwork;
use Siberfx\SocialAutopost\Networks\Bluesky\BlueskyText;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

const BSKY_SESSION = 'https://bsky.social/xrpc/com.atproto.server.createSession';
const BSKY_REFRESH = 'https://bsky.social/xrpc/com.atproto.server.refreshSession';
const BSKY_PROFILE = 'https://bsky.social/xrpc/app.bsky.actor.getProfile*';
const BSKY_RECORD = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';
const BSKY_BLOB = 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob';

beforeEach(function () {
    config()->set('social-autopost.networks.bluesky.identifier', 'ada.example.com');
    config()->set('social-autopost.networks.bluesky.app_password', 'aaaa-bbbb-cccc-dddd');
});

/** Store a Bluesky session, as a completed connect would. */
function blueskySeed(string $access = 'jwt-1', string $refresh = 'refresh-1'): void
{
    app(ConnectionStore::class)->put('bluesky', (new BlueskyAccount(
        accessJwt: $access,
        refreshJwt: $refresh,
        did: 'did:plc:ada',
        handle: 'ada.example.com',
        name: 'Ada',
        connectedAt: CarbonImmutable::parse('2026-10-01T10:00:00Z'),
    ))->toArray());
}

function blueskySession(): array
{
    return ['accessJwt' => 'jwt-1', 'refreshJwt' => 'refresh-1', 'did' => 'did:plc:ada', 'handle' => 'ada.example.com'];
}

it('sends the browser straight to the package callback instead of a consent screen', function () {
    expect(app(BlueskyNetwork::class)->beginConnection())
        ->toBe(route('social-autopost.callback', ['network' => 'bluesky']));
});

it('refuses to begin without an identifier and app password', function () {
    config()->set('social-autopost.networks.bluesky.app_password', null);

    app(BlueskyNetwork::class)->beginConnection();
})->throws(NotConfigured::class);

it('connects by signing in with the app password and stores the session', function () {
    Http::fake([
        BSKY_SESSION => Http::response(blueskySession()),
        BSKY_PROFILE => Http::response(['handle' => 'ada.example.com', 'displayName' => 'Ada Lovelace', 'avatar' => 'https://cdn.bsky.example/ada.jpg']),
    ]);

    $connection = app(BlueskyNetwork::class)->completeConnection(Request::create('/callback'));

    expect($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and(app(ConnectionStore::class)->get('bluesky'))->toMatchArray([
            'access_jwt' => 'jwt-1',
            'did' => 'did:plc:ada',
        ]);

    Http::assertSent(fn (ClientRequest $request) => $request->url() === BSKY_SESSION
        && $request['identifier'] === 'ada.example.com'
        && $request['password'] === 'aaaa-bbbb-cccc-dddd');
});

it('reports refused credentials as exchange_failed', function () {
    Http::fake([BSKY_SESSION => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401)]);

    try {
        app(BlueskyNetwork::class)->completeConnection(Request::create('/callback'));
        $this->fail('Expected ConnectionFailed.');
    } catch (ConnectionFailed $e) {
        expect($e->state)->toBe('exchange_failed')
            ->and($e->getMessage())->toBe('Bluesky rejected the request: Invalid identifier or password');
    }
});

it('posts the text with link facets and the link as an embed card', function () {
    blueskySeed();
    Http::fake([BSKY_RECORD => Http::response(['uri' => 'at://did:plc:ada/app.bsky.feed.post/3k2a', 'cid' => 'bafy'])]);

    $post = LinkPost::make('https://example.com/a', 'Title')
        ->description('Desc')
        ->commentary('Read this: {url}');

    expect(app(BlueskyNetwork::class)->publish(new Post, $post))->toBe('at://did:plc:ada/app.bsky.feed.post/3k2a');

    Http::assertSent(function (ClientRequest $request) {
        if ($request->url() !== BSKY_RECORD) {
            return false;
        }

        $record = $request['record'];

        return $request['repo'] === 'did:plc:ada'
            && $record['text'] === 'Read this: https://example.com/a'
            && $record['facets'][0]['index'] === ['byteStart' => 11, 'byteEnd' => 11 + strlen('https://example.com/a')]
            && $record['embed']['external']['uri'] === 'https://example.com/a'
            && $record['embed']['external']['title'] === 'Title';
    });
});

it('leaves the URL out of the default text: the card carries the link', function () {
    $post = LinkPost::make('https://example.com/a', 'Title')->description('Desc');

    expect(BlueskyText::compose($post))->toBe("Title\n\nDesc")
        ->and(BlueskyText::facets("Title\n\nDesc", 'https://example.com/a'))->toBe([]);
});

it('uploads the image as the card thumbnail', function () {
    blueskySeed();
    Http::fake([
        'https://cdn.example.com/cover.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        BSKY_BLOB => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafyblob'], 'mimeType' => 'image/jpeg', 'size' => 10]]),
        BSKY_RECORD => Http::response(['uri' => 'at://did:plc:ada/app.bsky.feed.post/3k2b']),
    ]);

    $post = LinkPost::make('https://example.com/a', 'Title')->image('https://cdn.example.com/cover.jpg');

    expect(app(BlueskyNetwork::class)->publish(new Post, $post))->toBe('at://did:plc:ada/app.bsky.feed.post/3k2b');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === BSKY_BLOB && $request->body() === 'jpeg-bytes');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === BSKY_RECORD
        && $request['record']['embed']['external']['thumb']['ref']['$link'] === 'bafyblob');
});

it('refreshes the session once on an expired token and posts again', function () {
    blueskySeed();
    Http::fake([
        BSKY_RECORD => Http::sequence()
            ->push(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400)
            ->push(['uri' => 'at://did:plc:ada/app.bsky.feed.post/3k2c']),
        BSKY_REFRESH => Http::response(['accessJwt' => 'jwt-2', 'refreshJwt' => 'refresh-2']),
    ]);

    expect(app(BlueskyNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title')))
        ->toBe('at://did:plc:ada/app.bsky.feed.post/3k2c');

    expect(app(ConnectionStore::class)->get('bluesky'))->toMatchArray([
        'access_jwt' => 'jwt-2',
        'refresh_jwt' => 'refresh-2',
    ]);
});

it('signs in again with the app password when the refresh is refused too', function () {
    blueskySeed();
    Http::fake([
        BSKY_RECORD => Http::sequence()
            ->push(['error' => 'ExpiredToken'], 400)
            ->push(['uri' => 'at://did:plc:ada/app.bsky.feed.post/3k2d']),
        BSKY_REFRESH => Http::response(['error' => 'ExpiredToken'], 400),
        BSKY_SESSION => Http::response(['accessJwt' => 'jwt-3', 'refreshJwt' => 'refresh-3', 'did' => 'did:plc:ada', 'handle' => 'ada.example.com']),
        BSKY_PROFILE => Http::response(['handle' => 'ada.example.com']),
    ]);

    expect(app(BlueskyNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title')))
        ->toBe('at://did:plc:ada/app.bsky.feed.post/3k2d');

    expect(app(ConnectionStore::class)->get('bluesky'))->toMatchArray(['access_jwt' => 'jwt-3']);
});

it('refuses to publish when not connected', function () {
    app(BlueskyNetwork::class)->publish(new Post, LinkPost::make('https://example.com/a', 'Title'));
})->throws(NotConnected::class);

it('reports the account as active and caches the status per token', function () {
    blueskySeed();
    Http::fake([BSKY_PROFILE => Http::response(['handle' => 'ada.example.com', 'displayName' => 'Ada Lovelace'])]);

    $first = app(BlueskyNetwork::class)->connection();
    $second = app(BlueskyNetwork::class)->connection();

    expect($first->status)->toBe('active')
        ->and($first->name)->toBe('Ada Lovelace')
        ->and($second->status)->toBe('active');

    Http::assertSentCount(1);
});

it('ends the session at Bluesky and forgets it', function () {
    blueskySeed();
    Http::fake(['https://bsky.social/xrpc/com.atproto.server.deleteSession' => Http::response([])]);

    expect(app(BlueskyNetwork::class)->disconnect())->toBeTrue()
        ->and(app(BlueskyNetwork::class)->isConnected())->toBeFalse();

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'deleteSession')
        && $request->header('Authorization')[0] === 'Bearer refresh-1');
});
