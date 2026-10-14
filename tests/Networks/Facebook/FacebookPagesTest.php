<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Networks\Facebook\FacebookConnection;
use Siberfx\SocialAutopost\Networks\Facebook\FacebookNetwork;
use Siberfx\SocialAutopost\Networks\Facebook\GraphClient;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config()->set('social-autopost.networks.facebook.app_id', 'app-id');
    config()->set('social-autopost.networks.facebook.app_secret', 'app-secret');
    config()->set('social-autopost.networks.facebook.page_id', null);
});

function facebookPagesExchangeFakes(): void
{
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'First', 'access_token' => 'token-111', 'picture' => ['data' => ['url' => 'https://pic/1.jpg']]],
            ['id' => '222', 'name' => 'Second', 'access_token' => 'token-222'],
            ['id' => '333', 'name' => 'No token'],
        ]]),
    ]);
}

it('keeps every managed Page with its token and selects the first', function () {
    facebookPagesExchangeFakes();

    $stored = app(GraphClient::class)->exchange('code');

    expect(array_column($stored->pages, 'id'))->toBe(['111', '222'])
        ->and($stored->selectedPages)->toBe(['111'])
        ->and($stored->accessToken)->toBe('token-111')
        ->and($stored->publicPages()[0]->picture)->toBe('https://pic/1.jpg');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'me/accounts')
        && str_contains(urldecode($request->url()), 'fields=id,name,access_token,picture{url}'));
});

it('selects the configured Page after connecting', function () {
    config()->set('social-autopost.networks.facebook.page_id', '222');
    facebookPagesExchangeFakes();

    $stored = app(GraphClient::class)->exchange('code');

    expect($stored->selectedPages)->toBe(['222'])->and($stored->pageId)->toBe('222');
});

it('reads a connection stored before 1.2 as its one Page, selected', function () {
    $stored = FacebookConnection::fromArray([
        'access_token' => 'page-token',
        'page_id' => '1234567',
        'name' => 'Old Page',
        'user_access_token' => 'user-token',
        'connected_at' => '2026-10-01T00:00:00+00:00',
    ]);

    expect($stored)->not->toBeNull()
        ->and($stored->pages)->toBe([['id' => '1234567', 'name' => 'Old Page', 'picture' => null, 'access_token' => 'page-token']])
        ->and($stored->selectedPages)->toBe(['1234567'])
        ->and(FacebookConnection::fromArray(['page_id' => 'x']))->toBeNull();
});

it('round-trips the pages and the selection through the store, without tokens in the connection', function () {
    facebookPagesExchangeFakes();
    app(ConnectionStore::class)->put('facebook', app(GraphClient::class)->exchange('code')->withSelected(['222', '111'])->toArray());

    $network = app(FacebookNetwork::class);

    expect($network->selectedPages())->toBe(['222', '111'])
        ->and(array_map(fn ($page) => $page->id, $network->pages()))->toBe(['111', '222'])
        ->and(json_encode($network->pages()))->not->toContain('token-');
});

it('posts to each chosen Page with that Page\'s own token', function () {
    facebookPagesExchangeFakes();
    app(ConnectionStore::class)->put('facebook', app(GraphClient::class)->exchange('code')->toArray());
    Http::fake([
        'https://graph.facebook.com/v25.0/111/feed' => Http::response(['id' => '111_1']),
        'https://graph.facebook.com/v25.0/222/feed' => Http::response(['id' => '222_1']),
    ]);
    $network = app(FacebookNetwork::class);
    $post = Post::query()->make(['title' => 'Hello', 'status' => 'published']);
    $link = LinkPost::make('https://example.test/a', 'Hello');

    $network->selectPages(['222']);

    expect($network->publish($post, $link))->toBe('222_1')
        ->and($network->publishToPage($post, $link, '111'))->toBe('111_1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v25.0/222/feed' && $request->hasHeader('Authorization', 'Bearer token-222'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v25.0/111/feed' && $request->hasHeader('Authorization', 'Bearer token-111'));

    expect(fn () => $network->selectPages(['999']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $network->selectPages([]))->toThrow(InvalidArgumentException::class);
});
