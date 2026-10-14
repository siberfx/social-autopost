<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Networks\LinkedIn\LinkedInNetwork;
use Siberfx\SocialAutopost\Networks\LinkedIn\OAuthClient;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

const LI_ACLS = 'https://api.linkedin.com/rest/organizationAcls*';

beforeEach(function () {
    config([
        'social-autopost.networks.linkedin.client_id' => 'client-id',
        'social-autopost.networks.linkedin.client_secret' => 'client-secret',
    ]);
});

/** The stored array, with organization pages when given. */
function storeLinkedInPages(?array $pages = null, ?array $selected = null): void
{
    app(ConnectionStore::class)->put('linkedin', array_filter([
        'access_token' => 'member-token',
        'author_urn' => 'urn:li:person:abc',
        'name' => 'Ada Lovelace',
        'pages' => $pages,
        'selected_pages' => $selected,
    ], fn ($value) => $value !== null));
}

function linkedInPagesTokenFakes(array $extra = []): void
{
    Http::fake($extra + [
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'member-token', 'expires_in' => 5184000]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc', 'name' => 'Ada Lovelace']),
    ]);
}

function linkedInOrgPages(): array
{
    return [
        ['id' => 'urn:li:person:abc', 'type' => 'member', 'name' => 'Ada Lovelace', 'picture' => null],
        ['id' => 'urn:li:organization:42', 'type' => 'organization', 'name' => 'Analytical Engines', 'picture' => null],
    ];
}

it('asks for the organization scopes only when organization pages are on', function () {
    parse_str((string) parse_url(app(OAuthClient::class)->authorizationUrl('s'), PHP_URL_QUERY), $off);

    config(['social-autopost.networks.linkedin.organizations' => true]);
    parse_str((string) parse_url(app(OAuthClient::class)->authorizationUrl('s'), PHP_URL_QUERY), $on);

    expect($off['scope'])->toBe('openid profile email w_member_social')
        ->and($on['scope'])->toBe('openid profile email w_member_social r_organization_admin w_organization_social');
});

it('stores the member as the only page, selected, when organization pages are off', function () {
    linkedInPagesTokenFakes();

    $stored = app(OAuthClient::class)->exchange('code');

    expect($stored['pages'])->toBe([['id' => 'urn:li:person:abc', 'type' => 'member', 'name' => 'Ada Lovelace', 'picture' => null]])
        ->and($stored['selected_pages'])->toBe(['urn:li:person:abc']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'organizationAcls'));
});

it('lists the organizations the member can post for, with their names', function () {
    config(['social-autopost.networks.linkedin.organizations' => true]);
    linkedInPagesTokenFakes([
        LI_ACLS => Http::response(['elements' => [
            ['role' => 'ADMINISTRATOR', 'organization' => 'urn:li:organization:42', 'state' => 'APPROVED'],
            ['role' => 'CONTENT_ADMINISTRATOR', 'organizationalTarget' => 'urn:li:organization:7', 'state' => 'APPROVED'],
            ['role' => 'ANALYST', 'organization' => 'urn:li:organization:9', 'state' => 'APPROVED'],
            ['role' => 'ADMINISTRATOR', 'organization' => 'urn:li:organization:42', 'state' => 'APPROVED'],
        ]]),
        'https://api.linkedin.com/rest/organizations/42' => Http::response(['localizedName' => 'Analytical Engines']),
        'https://api.linkedin.com/rest/organizations/7' => Http::response([], 404),
    ]);

    $stored = app(OAuthClient::class)->exchange('code');

    expect(array_column($stored['pages'], 'id'))->toBe(['urn:li:person:abc', 'urn:li:organization:42', 'urn:li:organization:7'])
        ->and(array_column($stored['pages'], 'name'))->toBe(['Ada Lovelace', 'Analytical Engines', null])
        ->and($stored['selected_pages'])->toBe(['urn:li:person:abc']);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizationAcls?')
        && str_contains($request->url(), 'q=roleAssignee')
        && $request->hasHeader('LinkedIn-Version', '202609')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
});

it('still connects with only the member page when LinkedIn refuses to list organizations', function () {
    config(['social-autopost.networks.linkedin.organizations' => true]);
    Log::spy();
    linkedInPagesTokenFakes([LI_ACLS => Http::response(['message' => 'Not enough permissions'], 403)]);

    $stored = app(OAuthClient::class)->exchange('code');

    expect(array_column($stored['pages'], 'id'))->toBe(['urn:li:person:abc']);
    Log::shouldHaveReceived('warning')->once();
});

it('reads a connection stored before pages existed as the member page, selected', function () {
    storeLinkedInPages();

    $network = app(LinkedInNetwork::class);

    expect($network->pages())->toEqual([new Page('urn:li:person:abc', Page::TYPE_MEMBER, 'Ada Lovelace')])
        ->and($network->selectedPages())->toBe(['urn:li:person:abc']);
});

it('posts to every chosen page as that page', function () {
    storeLinkedInPages(linkedInOrgPages(), ['urn:li:person:abc']);
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1'])]);
    $network = app(LinkedInNetwork::class);
    $post = Post::query()->make(['title' => 'Hello', 'status' => 'published']);
    $link = LinkPost::make('https://example.test/a', 'Hello');

    $network->selectPages(['urn:li:organization:42']);

    expect($network->publish($post, $link))->toBe('urn:li:share:1')
        ->and($network->publishToPage($post, $link, 'urn:li:person:abc'))->toBe('urn:li:share:1');

    $authors = Http::recorded()->map(fn ($pair) => $pair[0]['author'])->all();
    expect($authors)->toBe(['urn:li:organization:42', 'urn:li:person:abc']);

    expect(fn () => $network->publishToPage($post, $link, 'urn:li:organization:999'))->toThrow(InvalidArgumentException::class);
});

it('shows a new selection at once although the status is cached', function () {
    storeLinkedInPages(linkedInOrgPages(), ['urn:li:person:abc']);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true]),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['name' => 'Ada Lovelace']),
    ]);
    $network = app(LinkedInNetwork::class);

    expect($network->connection()->selectedPages)->toBe(['urn:li:person:abc']);

    $connection = $network->selectPages(['urn:li:organization:42', 'urn:li:person:abc']);

    expect($connection->selectedPages)->toBe(['urn:li:organization:42', 'urn:li:person:abc'])
        ->and($network->connection()->toArray()['pages'][1]['selected'])->toBeTrue();
    Http::assertSentCount(2);
});
