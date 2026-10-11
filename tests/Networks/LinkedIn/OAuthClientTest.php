<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\LinkedIn\OAuthClient;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'social-autopost.networks.linkedin.client_id' => 'client-id',
        'social-autopost.networks.linkedin.client_secret' => 'client-secret',
    ]);
});

function linkedInAuthorizeQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('builds the authorization url with the callback route by default', function () {
    $url = app(OAuthClient::class)->authorizationUrl('state-123');

    expect($url)->toStartWith('https://www.linkedin.com/oauth/v2/authorization?')
        ->and(linkedInAuthorizeQuery($url))->toBe([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'redirect_uri' => route('social-autopost.callback', ['network' => 'linkedin']),
            'state' => 'state-123',
            'scope' => 'openid profile email w_member_social',
        ]);
});

it('uses a configured redirect uri', function () {
    config(['social-autopost.networks.linkedin.redirect_uri' => 'https://www.example.com/linkedin/callback']);

    expect(linkedInAuthorizeQuery(app(OAuthClient::class)->authorizationUrl('s'))['redirect_uri'])
        ->toBe('https://www.example.com/linkedin/callback');
});

it('refuses to build a url without credentials', function () {
    config(['social-autopost.networks.linkedin.client_id' => null]);

    app(OAuthClient::class)->authorizationUrl('s');
})->throws(NotConfigured::class);

it('exchanges a code for the stored connection with the same redirect uri', function () {
    CarbonImmutable::setTestNow('2026-10-02 12:00:00');
    config(['social-autopost.networks.linkedin.redirect_uri' => 'https://www.example.com/linkedin/callback']);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'member-token',
            'expires_in' => 5184000,
            'scope' => 'email,openid,profile,w_member_social',
        ]),
        'https://api.linkedin.com/v2/userinfo' => Http::response([
            'sub' => 'abc123',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'picture' => 'https://media.licdn.com/a.jpg',
        ]),
    ]);

    $stored = app(OAuthClient::class)->exchange('auth-code');

    expect($stored)->toMatchArray([
        'access_token' => 'member-token',
        'author_urn' => 'urn:li:person:abc123',
        'name' => 'Ada Lovelace',
        'scopes' => ['email', 'openid', 'profile', 'w_member_social'],
        'expires_at' => '2026-12-01T12:00:00+00:00',
        'connected_at' => '2026-10-02T12:00:00+00:00',
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['redirect_uri'] === 'https://www.example.com/linkedin/callback'
        && $request['client_id'] === 'client-id'
        && $request['client_secret'] === 'client-secret');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/v2/userinfo'
        && $request->hasHeader('Authorization', 'Bearer member-token'));

    CarbonImmutable::setTestNow();
});

it('fails the exchange with LinkedIn\'s error', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
        'error' => 'invalid_redirect_uri',
        'error_description' => 'Unable to retrieve access token: appid/redirect uri/code verifier does not match authorization code.',
    ], 400)]);

    expect(fn () => app(OAuthClient::class)->exchange('auth-code'))
        ->toThrow(RequestFailed::class, 'appid/redirect uri/code verifier does not match');
});

it('fails the exchange when userinfo has no sub', function () {
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 't', 'expires_in' => 10]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['name' => 'No Sub']),
    ]);

    expect(fn () => app(OAuthClient::class)->exchange('c'))->toThrow(RequestFailed::class, 'no member id');
});

it('introspects a token, returning null when LinkedIn cannot answer', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/introspectToken' => Http::sequence()
        ->push(['active' => true, 'status' => 'active', 'expires_at' => 1795184000])
        ->push('down', 503)]);

    $client = app(OAuthClient::class);

    expect($client->introspect('t'))->toMatchArray(['active' => true, 'status' => 'active'])
        ->and($client->introspect('t'))->toBeNull();

    Http::assertSent(fn (Request $request) => $request['token'] === 't' && $request['client_secret'] === 'client-secret');
});

it('revokes a token', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::sequence()->push('', 200)->push(['error' => 'x'], 500)]);

    $client = app(OAuthClient::class);

    expect($client->revoke('t'))->toBeTrue()
        ->and($client->revoke('t'))->toBeFalse();
});

it('splits scopes on spaces and commas and ignores an empty value', function () {
    expect(OAuthClient::scopes('openid,profile  email'))->toBe(['openid', 'profile', 'email'])
        ->and(OAuthClient::scopes(''))->toBe([])
        ->and(OAuthClient::scopes(null))->toBe([]);
});
