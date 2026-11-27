<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\Networks\LinkedIn\Community\Comment;
use Siberfx\SocialAutopost\Networks\LinkedIn\Community\Community;
use Siberfx\SocialAutopost\Networks\LinkedIn\Community\Reaction;
use Siberfx\SocialAutopost\Networks\LinkedIn\LinkedInNetwork;
use Siberfx\SocialAutopost\Networks\LinkedIn\OAuthClient;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

const LIC_MEMBER = 'urn:li:person:abc';
const LIC_ACME = 'urn:li:organization:111';
const LIC_POST = 'urn:li:share:7001';
const LIC_POST_ENC = 'urn%3Ali%3Ashare%3A7001';
const LIC_ACME_ENC = 'urn%3Ali%3Aorganization%3A111';

beforeEach(function () {
    config([
        'social-autopost.networks.linkedin.enabled' => true,
        'social-autopost.networks.linkedin.client_id' => 'client-id',
        'social-autopost.networks.linkedin.client_secret' => 'client-secret',
    ]);
});

/** A LinkedIn connection with the member's profile and one organization page. */
function communityConnect(array $selected = [LIC_MEMBER]): void
{
    app(ConnectionStore::class)->put('linkedin', [
        'access_token' => 'member-token',
        'author_urn' => LIC_MEMBER,
        'name' => 'Ada',
        'expires_at' => CarbonImmutable::now()->addDays(50)->toIso8601String(),
        'connected_at' => CarbonImmutable::now()->toIso8601String(),
        'pages' => [
            ['id' => LIC_MEMBER, 'type' => Page::TYPE_MEMBER, 'name' => 'Ada', 'picture' => null],
            ['id' => LIC_ACME, 'type' => Page::TYPE_ORGANIZATION, 'name' => 'Acme', 'picture' => null],
        ],
        'selected_pages' => $selected,
    ]);
}

function communityAcmeShare(array $attributes = []): SharedPost
{
    return SharedPost::query()->create($attributes + [
        'shareable_type' => 'post',
        'shareable_id' => 1,
        'network' => 'linkedin',
        'target' => LIC_ACME,
        'post_id' => LIC_POST,
        'status' => SharedPost::STATUS_POSTED,
        'trigger' => SharedPost::TRIGGER_MANUAL,
        'posted_at' => now(),
    ]);
}

it('asks for the community scopes only when community is on', function () {
    $scope = function (): string {
        parse_str((string) parse_url(app(OAuthClient::class)->authorizationUrl('s'), PHP_URL_QUERY), $query);

        return $query['scope'];
    };

    expect($scope())->toBe('openid profile email w_member_social');

    config(['social-autopost.networks.linkedin.community' => true]);

    expect($scope())->toBe('openid profile email w_member_social r_organization_social rw_organization_admin');
});

it('is reached through Social::linkedin()->community() and the driver', function () {
    communityConnect();

    expect(Social::linkedin())->toBeInstanceOf(LinkedInNetwork::class)
        ->and(Social::linkedin()->community())->toBeInstanceOf(Community::class)
        ->and(app(LinkedInNetwork::class)->community())->toBeInstanceOf(Community::class);
});

it('needs a connection', function () {
    Social::linkedin()->community();
})->throws(NotConnected::class, 'LinkedIn is not connected');

it('refuses a switched-off LinkedIn and a network that is not LinkedIn', function () {
    config(['social-autopost.networks.fake.enabled' => true]);

    expect(fn () => Social::linkedin('fake'))->toThrow(InvalidArgumentException::class, 'is not');

    config(['social-autopost.networks.linkedin.enabled' => false]);

    expect(fn () => Social::linkedin())->toThrow(NetworkDisabled::class);
});

it('lists the comments of a post', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/socialActions/*' => Http::response(['elements' => [[
            'id' => '6801',
            'commentUrn' => 'urn:li:comment:(urn:li:activity:1,6801)',
            'actor' => 'urn:li:person:zzz',
            'object' => LIC_POST,
            'message' => ['text' => 'Great read!'],
            'created' => ['actor' => 'urn:li:person:zzz', 'time' => 1_790_000_000_000],
        ]]]),
    ]);

    $comments = Social::linkedin()->community()->comments(LIC_POST, count: 500);

    expect($comments)->toHaveCount(1)
        ->and($comments[0])->toBeInstanceOf(Comment::class)
        ->and($comments[0]->id)->toBe('6801')
        ->and($comments[0]->text)->toBe('Great read!')
        ->and($comments[0]->actor)->toBe('urn:li:person:zzz')
        ->and($comments[0]->createdAt?->getTimestampMs())->toBe(1_790_000_000_000)
        ->and($comments[0]->toArray()['urn'])->toBe('urn:li:comment:(urn:li:activity:1,6801)');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/socialActions/'.LIC_POST_ENC.'/comments?count=100&start=0'
        && $request->hasHeader('Authorization', 'Bearer member-token')
        && $request->hasHeader('LinkedIn-Version', '202609')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
});

it('comments as the page the share went to', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/socialActions/*' => Http::response(['id' => '6900', 'message' => ['text' => 'Thanks!']], 201),
    ]);

    $comment = Social::linkedin()->community()->comment(communityAcmeShare(), 'Thanks!');

    expect($comment->id)->toBe('6900')->and($comment->actor)->toBe(LIC_ACME);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.linkedin.com/rest/socialActions/'.LIC_POST_ENC.'/comments'
        && $request['actor'] === LIC_ACME
        && $request['object'] === LIC_POST
        && $request['message'] === ['text' => 'Thanks!']
        && ! isset($request['parentComment']));
});

it('takes the comment id from the x-restli-id header when the body has none', function () {
    communityConnect();
    Http::fake(['*' => Http::response(null, 201, ['x-restli-id' => '6950'])]);

    $comment = Social::linkedin()->community()->comment(LIC_POST, 'Hi');

    expect($comment->id)->toBe('6950')
        ->and($comment->actor)->toBe(LIC_MEMBER)
        ->and($comment->text)->toBe('Hi');
});

it('replies to a comment', function () {
    communityConnect();
    Http::fake(['*' => Http::response(['id' => '6901'], 201)]);
    $parent = Comment::fromApi(['id' => '6801', 'commentUrn' => 'urn:li:comment:(urn:li:activity:1,6801)']);

    Social::linkedin()->community()->comment(LIC_POST, 'Indeed', actor: LIC_ACME, replyTo: $parent);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/socialActions/'.rawurlencode('urn:li:comment:(urn:li:activity:1,6801)').'/comments')
        && $request['parentComment'] === 'urn:li:comment:(urn:li:activity:1,6801)'
        && $request['object'] === LIC_POST
        && $request['actor'] === LIC_ACME);
});

it('refuses an actor that is not one of the connection pages, and empty comments', function () {
    communityConnect();
    Http::fake();

    expect(fn () => Social::linkedin()->community()->comment(LIC_POST, 'Hi', actor: 'urn:li:organization:999'))
        ->toThrow(InvalidArgumentException::class, 'is not a page of this LinkedIn account')
        ->and(fn () => Social::linkedin()->community()->comment(LIC_POST, '   '))
        ->toThrow(InvalidArgumentException::class, 'needs some text');

    Http::assertNothingSent();
});

it('deletes a comment as the actor, and tolerates one already gone', function () {
    communityConnect([LIC_ACME]);
    Http::fakeSequence()->push(null, 204)->push(['message' => 'Not found'], 404);

    Social::linkedin()->community()->deleteComment(LIC_POST, '6801');
    Social::linkedin()->community()->deleteComment(LIC_POST, Comment::fromApi(['id' => '6802']));

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linkedin.com/rest/socialActions/'.LIC_POST_ENC.'/comments/6801?actor='.LIC_ACME_ENC);
    Http::assertSentCount(2);
});

it('reacts and removes a reaction', function () {
    communityConnect();
    Http::fake(['*' => Http::response(null, 201)]);

    Social::linkedin()->community()->react(LIC_POST, 'praise', actor: LIC_ACME);
    Social::linkedin()->community()->unreact(LIC_POST, actor: LIC_ACME);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.linkedin.com/rest/reactions?actor='.LIC_ACME_ENC
        && $request['root'] === LIC_POST
        && $request['reactionType'] === 'PRAISE');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linkedin.com/rest/reactions/(actor:'.LIC_ACME_ENC.',entity:'.LIC_POST_ENC.')');
});

it('refuses an unknown reaction type', function () {
    communityConnect();

    Social::linkedin()->community()->react(LIC_POST, 'ANGRY');
})->throws(InvalidArgumentException::class, 'is not a LinkedIn reaction');

it('lists reactions', function () {
    communityConnect();
    Http::fake(['https://api.linkedin.com/rest/reactions/*' => Http::response(['elements' => [
        ['reactionType' => 'EMPATHY', 'root' => LIC_POST, 'created' => ['actor' => 'urn:li:person:zzz', 'time' => 1_790_000_000_000]],
    ]])]);

    $reactions = Social::linkedin()->community()->reactions(LIC_POST);

    expect($reactions)->toHaveCount(1)
        ->and($reactions[0]->type)->toBe(Reaction::EMPATHY)
        ->and($reactions[0]->actor)->toBe('urn:li:person:zzz')
        ->and($reactions[0]->toArray()['root'])->toBe(LIC_POST);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/reactions/(entity:'.LIC_POST_ENC.')?q=entity')
        && $request->hasHeader('X-RestLi-Method', 'FINDER'));
});

it('summarises the reactions and comments of a post', function () {
    communityConnect();
    Http::fake(['https://api.linkedin.com/rest/socialMetadata/*' => Http::response([
        'reactionSummaries' => [
            'LIKE' => ['reactionType' => 'LIKE', 'count' => 12],
            'PRAISE' => ['reactionType' => 'PRAISE', 'count' => 3],
        ],
        'commentSummary' => ['count' => 7, 'topLevelCount' => 5],
        'commentsState' => 'OPEN',
    ])]);

    $summary = Social::linkedin()->community()->summary(communityAcmeShare());

    expect($summary->toArray())->toBe([
        'reactions' => ['LIKE' => 12, 'PRAISE' => 3],
        'total_reactions' => 15,
        'comments' => 7,
        'top_level_comments' => 5,
        'comments_open' => true,
    ]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/socialMetadata/'.LIC_POST_ENC);
});

it('lists the recent posts of a page', function () {
    communityConnect();
    Http::fake(['https://api.linkedin.com/rest/posts*' => Http::response(['elements' => [['id' => LIC_POST, 'author' => LIC_ACME]]])]);

    expect(Social::linkedin()->community()->posts(LIC_ACME, 5))->toBe([['id' => LIC_POST, 'author' => LIC_ACME]]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/posts?q=author&author='.LIC_ACME_ENC.'&count=5&start=0&sortBy=LAST_MODIFIED'
        && $request->hasHeader('X-RestLi-Method', 'FINDER'));
});

it('reads one post, null when LinkedIn no longer has it', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/posts/'.LIC_POST_ENC => Http::response(['id' => LIC_POST, 'commentary' => 'Hi']),
        'https://api.linkedin.com/rest/posts/*' => Http::response(['message' => 'Not found'], 404),
    ]);

    expect(Social::linkedin()->community()->post(LIC_POST))->toBe(['id' => LIC_POST, 'commentary' => 'Hi'])
        ->and(Social::linkedin()->community()->post('urn:li:share:404'))->toBeNull();
});

it('deletes a post and marks its LinkedIn share records deleted', function () {
    communityConnect();
    Http::fake(['*' => Http::response(null, 204)]);
    $model = Post::query()->create(['title' => 'Hello', 'status' => 'published']);
    $record = communityAcmeShare(['shareable_id' => $model->getKey()]);
    $other = communityAcmeShare(['shareable_id' => $model->getKey(), 'network' => 'x', 'target' => null]);

    expect(Social::wasPosted($model, 'linkedin'))->toBeTrue();

    Social::linkedin()->community()->deletePost($record);

    expect($record->status)->toBe(SharedPost::STATUS_DELETED)
        ->and($record->fresh()?->status)->toBe(SharedPost::STATUS_DELETED)
        ->and($other->fresh()?->status)->toBe(SharedPost::STATUS_POSTED)
        ->and(Social::wasPosted($model, 'linkedin'))->toBeFalse()
        ->and(Social::wasPosted($model, 'x'))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linkedin.com/rest/posts/'.LIC_POST_ENC
        && $request->hasHeader('X-RestLi-Method', 'DELETE'));
});

it('deletes a post LinkedIn no longer has', function () {
    communityConnect();
    Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);
    $record = communityAcmeShare();

    Social::linkedin()->community()->deletePost(LIC_POST);

    expect($record->fresh()?->status)->toBe(SharedPost::STATUS_DELETED);
});

it('refuses a share without a post id, a share to another network, and a value that is not a URN', function () {
    communityConnect();
    Http::fake();
    $record = communityAcmeShare();
    $record->post_id = null;

    expect(fn () => Social::linkedin()->community()->summary($record))
        ->toThrow(InvalidArgumentException::class, 'has no LinkedIn post id')
        ->and(fn () => Social::linkedin()->community()->summary(communityAcmeShare(['network' => 'x'])))
        ->toThrow(InvalidArgumentException::class, 'not LinkedIn')
        ->and(fn () => Social::linkedin()->community()->summary('7001'))
        ->toThrow(InvalidArgumentException::class, 'is not a LinkedIn URN');

    Http::assertNothingSent();
});

it('reads the lifetime statistics of an organization page', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 1520]),
        'https://api.linkedin.com/rest/organizationalEntityShareStatistics*' => Http::response(['elements' => [[
            'organizationalEntity' => LIC_ACME,
            'totalShareStatistics' => [
                'impressionCount' => 1000, 'uniqueImpressionsCount' => 800, 'clickCount' => 40,
                'likeCount' => 30, 'commentCount' => 20, 'shareCount' => 10,
            ],
        ]]]),
    ]);

    $stats = Social::linkedin()->community()->statistics(LIC_ACME);

    expect($stats->toArray())->toBe([
        'organization' => LIC_ACME,
        'followers' => 1520,
        'impressions' => 1000,
        'unique_impressions' => 800,
        'clicks' => 40,
        'likes' => 30,
        'comments' => 20,
        'shares' => 10,
        'engagement' => 0.1,
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/networkSizes/'.LIC_ACME_ENC.'?edgeType=COMPANY_FOLLOWED_BY_MEMBER');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity='.LIC_ACME_ENC);
});

it('sums the daily statistics over a range', function () {
    communityConnect();
    $day = ['impressionCount' => 100, 'clickCount' => 5];
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 10]),
        'https://api.linkedin.com/rest/organizationalEntityShareStatistics*' => Http::response(['elements' => [
            ['totalShareStatistics' => $day],
            ['totalShareStatistics' => $day],
        ]]),
    ]);
    $from = CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-11-03 00:00:00', 'UTC');

    $stats = Social::linkedin()->community()->statistics(LIC_ACME, $from, $to);

    expect($stats->impressions)->toBe(200)->and($stats->clicks)->toBe(10)->and($stats->engagement)->toBe(0.05);
    Http::assertSent(fn (Request $request) => str_contains($request->url(),
        '&timeIntervals=(timeRange:(start:'.$from->getTimestampMs().',end:'.$to->getTimestampMs().'),timeGranularityType:DAY)'));
});

it('only reads the statistics of an organization page', function () {
    communityConnect();

    Social::linkedin()->community()->followers(LIC_MEMBER);
})->throws(InvalidArgumentException::class, 'is not a LinkedIn organization page');

it('throws LinkedIn errors and shows the connection as expired on 401', function () {
    communityConnect();
    Http::fake([
        'https://api.linkedin.com/rest/socialMetadata/*' => Http::response(['message' => 'Expired token'], 401),
        'https://www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => true, 'status' => 'active']),
        'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc']),
    ]);

    expect(fn () => Social::linkedin()->community()->summary(LIC_POST))
        ->toThrow(RequestFailed::class, 'LinkedIn rejected the request: Expired token');

    expect(Social::connection('linkedin')->status)->toBe('expired');
});

it('turns a network failure into RequestFailed', function () {
    communityConnect();
    Http::fake(fn () => throw new ConnectionException('timed out'));

    Social::linkedin()->community()->comments(LIC_POST);
})->throws(RequestFailed::class, 'Could not reach LinkedIn');

it('works on the configured community page by default', function () {
    communityConnect();
    config(['social-autopost.networks.linkedin.community_page' => '111']);
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 42]),
        'https://api.linkedin.com/rest/posts*' => Http::response(['elements' => []]),
        'https://api.linkedin.com/rest/reactions*' => Http::response(null, 201),
    ]);

    $community = Social::linkedin()->community();

    expect($community->defaultPage()?->id)->toBe(LIC_ACME)
        ->and($community->followers())->toBe(42);

    $community->posts();
    $community->react(LIC_POST);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/posts?q=author&author='.LIC_ACME_ENC));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/rest/reactions?actor='.LIC_ACME_ENC);
});

it('falls back to the first organization page, and refuses a configured page the connection does not have', function () {
    communityConnect();

    expect(Social::linkedin()->community()->defaultPage()?->id)->toBe(LIC_ACME);

    config(['social-autopost.networks.linkedin.community_page' => 'urn:li:organization:999']);

    expect(fn () => Social::linkedin()->community()->defaultPage())
        ->toThrow(InvalidArgumentException::class, 'is not a page of this LinkedIn account');
});

it('needs an organization page for statistics when none is given', function () {
    app(ConnectionStore::class)->put('linkedin', ['access_token' => 'member-token', 'author_urn' => LIC_MEMBER]);

    expect(Social::linkedin()->community()->defaultPage())->toBeNull();

    Social::linkedin()->community()->statistics();
})->throws(InvalidArgumentException::class, 'has no organization page');

it('keeps only the configured organizations when connecting', function (mixed $only) {
    config([
        'social-autopost.networks.linkedin.organizations' => true,
        'social-autopost.networks.linkedin.organization_ids' => $only,
    ]);
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => LIC_ACME],
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:222'],
        ]]),
        'https://api.linkedin.com/rest/organizations/*' => Http::response(['localizedName' => 'Acme']),
    ]);

    $pages = app(OAuthClient::class)->organizations('tok');

    expect(array_map(fn (Page $page) => $page->id, $pages))->toBe([LIC_ACME]);
})->with([
    'ids as a string' => ' 111 , 333',
    'URNs in an array' => [[LIC_ACME]],
]);

it('keeps every organization when none is configured', function () {
    config(['social-autopost.networks.linkedin.organizations' => true]);
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => LIC_ACME],
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:222'],
        ]]),
        'https://api.linkedin.com/rest/organizations/*' => Http::response(['localizedName' => 'Acme']),
    ]);

    expect(array_map(fn (Page $page) => $page->id, app(OAuthClient::class)->organizations('tok')))
        ->toBe([LIC_ACME, 'urn:li:organization:222']);
});
