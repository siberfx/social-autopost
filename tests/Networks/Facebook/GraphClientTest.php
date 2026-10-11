<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\Facebook\GraphClient;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

const FB_FEED = 'https://graph.facebook.com/v25.0/1234567/feed';

function fbQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

beforeEach(function () {
    config()->set('social-autopost.networks.facebook.app_id', 'app-id');
    config()->set('social-autopost.networks.facebook.app_secret', 'app-secret');
    config()->set('social-autopost.networks.facebook.page_id', null);
    config()->set('social-autopost.networks.facebook.redirect_uri', null);
    config()->set('social-autopost.networks.facebook.api_version', 'v25.0');
});

it('builds the Login dialog URL with the Page scopes and the default callback', function () {
    $url = app(GraphClient::class)->authorizationUrl('state-123');

    expect(str_starts_with($url, 'https://www.facebook.com/v25.0/dialog/oauth?'))->toBeTrue()
        ->and(fbQuery($url))->toMatchArray([
            'client_id' => 'app-id',
            'response_type' => 'code',
            'state' => 'state-123',
            'scope' => 'pages_show_list,pages_manage_posts,pages_read_engagement',
            'redirect_uri' => url('/social/facebook/callback'),
        ]);
});

it('uses the configured redirect URI when one is set', function () {
    config()->set('social-autopost.networks.facebook.redirect_uri', 'https://example.com/fb/cb');

    expect(fbQuery(app(GraphClient::class)->authorizationUrl('s'))['redirect_uri'])->toBe('https://example.com/fb/cb');
});

it('refuses to build a dialog URL without the app credentials', function () {
    config()->set('social-autopost.networks.facebook.app_secret', null);

    expect(fn () => app(GraphClient::class)->authorizationUrl('s'))->toThrow(NotConfigured::class);
});

it('exchanges the code for a long-lived user token and takes the Page token', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user', 'expires_in' => 5184000]),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '1234567', 'name' => 'Siberfx Page', 'access_token' => 'page-token'],
        ]]),
    ]);

    $stored = app(GraphClient::class)->exchange('auth-code');

    expect($stored->accessToken)->toBe('page-token')
        ->and($stored->pageId)->toBe('1234567')
        ->and($stored->name)->toBe('Siberfx Page')
        ->and($stored->userAccessToken)->toBe('long-user');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/oauth/access_token')
        && str_contains($request->url(), 'grant_type=fb_exchange_token')
        && str_contains($request->url(), 'fb_exchange_token=short-user'));
});

it('picks the configured Page when the account manages several', function () {
    config()->set('social-autopost.networks.facebook.page_id', '999');

    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '1234567', 'name' => 'Other', 'access_token' => 'other-token'],
            ['id' => '999', 'name' => 'Wanted', 'access_token' => 'wanted-token'],
        ]]),
    ]);

    $stored = app(GraphClient::class)->exchange('auth-code');

    expect($stored->pageId)->toBe('999')->and($stored->accessToken)->toBe('wanted-token');
});

it('refuses the exchange when the account does not manage the configured Page', function () {
    config()->set('social-autopost.networks.facebook.page_id', '999');

    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => []]),
    ]);

    expect(fn () => app(GraphClient::class)->exchange('auth-code'))
        ->toThrow(RequestFailed::class, 'This Facebook account does not manage the Page 999.');
});

it('fails the exchange with Facebook\'s reason when the code is refused', function () {
    Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::response(['error' => ['message' => 'Invalid code']], 400)]);

    expect(fn () => app(GraphClient::class)->exchange('bad'))
        ->toThrow(RequestFailed::class, 'Facebook rejected the request: Invalid code');
});

it('posts the commentary and the link to the Page feed and returns the post id', function () {
    Http::fake([FB_FEED => Http::response(['id' => '1234567_42'])]);

    $id = app(GraphClient::class)->postLink('page-token', '1234567',
        LinkPost::make('https://example.com/x', 'Title')->commentary('New: {title}'));

    expect($id)->toBe('1234567_42');
    Http::assertSent(fn (Request $request) => $request->url() === FB_FEED
        && $request['message'] === 'New: Title'
        && $request['link'] === 'https://example.com/x'
        && $request->hasHeader('Authorization', 'Bearer page-token'));
});

it('does not send the description, since Facebook takes the preview from the page', function () {
    Http::fake([FB_FEED => Http::response(['id' => '1'])]);

    app(GraphClient::class)->postLink('t', '1234567',
        LinkPost::make('https://example.com/x', 'X')->description('Summary text')->commentary('{title}'));

    Http::assertSent(fn (Request $request) => ! str_contains((string) $request->body(), 'Summary'));
});

it('returns null when Facebook accepts the post without an id', function () {
    Http::fake([FB_FEED => Http::response([], 200)]);

    expect(app(GraphClient::class)->postLink('t', '1234567', LinkPost::make('https://example.com/x', 'X')))->toBeNull();
});

it('reports a refused post with Facebook\'s message, and an invalid token as unauthorized', function () {
    Http::fake([FB_FEED => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

    try {
        app(GraphClient::class)->postLink('t', '1234567', LinkPost::make('https://example.com/x', 'X'));
        $this->fail('Expected a refused post to throw.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toBe('Facebook rejected the request: Invalid OAuth access token.')
            ->and($e->isUnauthorized())->toBeTrue()
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('treats a rate limit as retryable', function () {
    Http::fake([FB_FEED => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

    try {
        app(GraphClient::class)->postLink('t', '1234567', LinkPost::make('https://example.com/x', 'X'));
        $this->fail('Expected a rate limit to throw.');
    } catch (RequestFailed $e) {
        expect($e->isRetryable())->toBeTrue()
            ->and($e->isUnauthorized())->toBeFalse();
    }
});

it('treats an unreachable Facebook as retryable', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    try {
        app(GraphClient::class)->postLink('t', '1234567', LinkPost::make('https://example.com/x', 'X'));
        $this->fail('Expected an unreachable Facebook to throw.');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toStartWith('Could not reach Facebook')
            ->and($e->isRetryable())->toBeTrue();
    }
});

it('asks debug_token with the app token, and the Page profile with the Page token', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]),
        'https://graph.facebook.com/v25.0/1234567?*' => Http::response(['name' => 'Siberfx Page']),
    ]);

    $client = app(GraphClient::class);

    expect($client->debugToken('page-token'))->toMatchArray(['is_valid' => true])
        ->and($client->pageProfile('page-token', '1234567'))->toMatchArray(['name' => 'Siberfx Page']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'debug_token')
        && fbQuery($request->url())['access_token'] === 'app-id|app-secret');
});

it('returns null from debug_token when Facebook refuses the question', function () {
    Http::fake(['https://graph.facebook.com/v25.0/debug_token*' => Http::response(['error' => ['message' => 'nope']], 400)]);

    expect(app(GraphClient::class)->debugToken('page-token'))->toBeNull();
});

it('removes the app permissions with the user token', function () {
    Http::fake(['https://graph.facebook.com/v25.0/me/permissions' => Http::response(['success' => true])]);

    expect(app(GraphClient::class)->revoke('user-token'))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer user-token'));
});

it('reports a refused revocation as false', function () {
    Http::fake(['https://graph.facebook.com/v25.0/me/permissions' => Http::response(['error' => ['message' => 'nope']], 400)]);

    expect(app(GraphClient::class)->revoke('user-token'))->toBeFalse();
});

it('sends the URL once: it is in the link field, not repeated in the message', function () {
    Http::fake([FB_FEED => Http::response(['id' => '1'])]);

    app(GraphClient::class)->postLink('t', '1234567',
        LinkPost::make('https://example.com/x', 'Title'));

    Http::assertSent(fn (Request $request) => $request['message'] === 'Title'
        && $request['link'] === 'https://example.com/x');
});
