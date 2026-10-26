<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\X\OAuthClient;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config()->set('social-autopost.networks.x.client_id', 'x-client');
    config()->set('social-autopost.networks.x.client_secret', 'x-secret');
    config()->set('social-autopost.networks.x.redirect_uri', null);
});

/** @return array<string, string> */
function authorizationQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('builds the PKCE authorization url with the callback route by default', function () {
    $url = app(OAuthClient::class)->authorizationUrl('state-1', 'challenge-1');

    expect(str_starts_with($url, 'https://x.com/i/oauth2/authorize?'))->toBeTrue();
    expect(authorizationQuery($url))->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'x-client',
        'redirect_uri' => route('social-autopost.callback', ['network' => 'x']),
        'state' => 'state-1',
        'scope' => 'tweet.read tweet.write users.read media.write offline.access',
        'code_challenge' => 'challenge-1',
        'code_challenge_method' => 'S256',
    ]);
});

it('uses a configured redirect uri', function () {
    config()->set('social-autopost.networks.x.redirect_uri', 'https://example.com/x/callback');

    $query = authorizationQuery(app(OAuthClient::class)->authorizationUrl('s', 'c'));

    expect($query['redirect_uri'])->toBe('https://example.com/x/callback');
});

it('uses the scopes from the network config when set', function () {
    config()->set('social-autopost.networks.x.scopes', ['tweet.write', 'users.read']);

    $query = authorizationQuery(app(OAuthClient::class)->authorizationUrl('s', 'c'));

    expect($query['scope'])->toBe('tweet.write users.read');
});

it('refuses to build a url without a client id', function () {
    config()->set('social-autopost.networks.x.client_id', null);

    app(OAuthClient::class)->authorizationUrl('s', 'c');
})->throws(NotConfigured::class);

it('derives the S256 challenge from the verifier (RFC 7636 example)', function () {
    expect(OAuthClient::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

it('exchanges a code with the verifier, the same redirect uri and basic auth', function () {
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'scope' => 'tweet.read tweet.write users.read media.write offline.access',
        ]),
        'https://api.x.com/2/users/me*' => Http::response(['data' => [
            'id' => '99',
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'profile_image_url' => 'https://pbs.example/ada.jpg',
        ]]),
    ]);

    $account = app(OAuthClient::class)->exchange('code-1', 'verifier-1');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/2/oauth2/token')) {
            return false;
        }

        parse_str($request->body(), $body);

        return $body['grant_type'] === 'authorization_code'
            && $body['code'] === 'code-1'
            && $body['code_verifier'] === 'verifier-1'
            && $body['redirect_uri'] === route('social-autopost.callback', ['network' => 'x'])
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('x-client:x-secret')
            && ! isset($body['client_id']);
    });

    expect($account->accessToken)->toBe('access-1')
        ->and($account->refreshToken)->toBe('refresh-1')
        ->and($account->username)->toBe('ada')
        ->and($account->name)->toBe('Ada Lovelace')
        ->and($account->picture)->toBe('https://pbs.example/ada.jpg')
        ->and($account->scopes)->toBe(['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'])
        ->and($account->connectedAt)->not->toBeNull();
});

it('sends the client id in the body for a public app without a secret', function () {
    config()->set('social-autopost.networks.x.client_secret', null);

    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access-1']),
        'https://api.x.com/2/users/me*' => Http::response(['data' => ['username' => 'ada']]),
    ]);

    app(OAuthClient::class)->exchange('code-1', 'verifier-1');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/2/oauth2/token')) {
            return false;
        }

        parse_str($request->body(), $body);

        return $body['client_id'] === 'x-client' && $request->header('Authorization') === [];
    });
});

it('fails the exchange with the reason X gives', function () {
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'Missing valid code_verifier.'], 400),
    ]);

    app(OAuthClient::class)->exchange('code-1', 'bad');
})->throws(RequestFailed::class, 'X rejected the request: Missing valid code_verifier.');

it('fails the exchange when X returns no access token', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['token_type' => 'bearer'])]);

    app(OAuthClient::class)->exchange('code-1', 'verifier-1');
})->throws(RequestFailed::class, 'X returned no access token.');

it('fails the exchange when X does not return the account for the new token', function () {
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access-1']),
        'https://api.x.com/2/users/me*' => Http::response(['detail' => 'Unauthorized'], 401),
    ]);

    app(OAuthClient::class)->exchange('code-1', 'verifier-1');
})->throws(RequestFailed::class, 'X did not return the account for the new token.');

it('refreshes tokens and returns the rotated refresh token', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response([
        'access_token' => 'access-2',
        'refresh_token' => 'refresh-2',
    ])]);

    $tokens = app(OAuthClient::class)->refresh('refresh-1');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2/oauth2/token')
        && str_contains($request->body(), 'grant_type=refresh_token')
        && str_contains($request->body(), 'refresh_token=refresh-1'));

    expect($tokens)->toBe(['access_token' => 'access-2', 'refresh_token' => 'refresh-2']);
});

it('returns null when a refresh is refused or cannot be reached', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(app(OAuthClient::class)->refresh('refresh-1'))->toBeNull();

    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(app(OAuthClient::class)->refresh('refresh-1'))->toBeNull();
});

it('reports the profile as active, expired or unknown', function (mixed $response, string $status) {
    Http::fake(['https://api.x.com/2/users/me*' => $response]);

    expect(app(OAuthClient::class)->profile('t'))->toMatchArray(['status' => $status]);
})->with([
    'active' => [fn () => Http::response(['data' => ['username' => 'ada']]), 'active'],
    'expired on 401' => [fn () => Http::response(['detail' => 'Unauthorized'], 401), 'expired'],
    'unknown on 503' => [fn () => Http::response(['detail' => 'Server error'], 503), 'unknown'],
]);

it('reports the profile as unknown when X cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(app(OAuthClient::class)->profile('t'))->toMatchArray(['status' => 'unknown', 'user' => []]);
});

it('revokes a token at X and reports X confirmed it', function () {
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response(['data' => ['revoked' => true]])]);

    expect(app(OAuthClient::class)->revoke('access-1'))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2/oauth2/revoke')
        && str_contains($request->body(), 'token=access-1'));
});

it('reports a revocation X refuses as not confirmed', function () {
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response(['detail' => 'nope'], 400)]);

    expect(app(OAuthClient::class)->revoke('access-1'))->toBeFalse();
});
