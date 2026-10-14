<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Jobs\PublishToSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\Columns;
use Siberfx\SocialAutopost\Support\SocialProps;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Tests\Fixtures\FakePagesNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

beforeEach(function () {
    $this->network('paged');
    $this->connect('paged');
});

function pagedPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => 'published']);
}

it('lists the pages with the selected one flagged in the connection', function () {
    $data = Social::connection('paged')->toArray();

    expect($data['pages'])->toHaveCount(3)
        ->and($data['pages'][0])->toBe(['id' => 'me', 'type' => 'member', 'name' => 'Me', 'picture' => null, 'selected' => true])
        ->and($data['pages'][1]['selected'])->toBeFalse()
        ->and($data['selected_pages'])->toBe(['me']);
});

it('shows no pages for a network with one account', function () {
    $this->network('fake');
    $this->connect('fake');

    $data = Social::connection('fake')->toArray();

    expect($data['pages'])->toBe([])
        ->and($data['selected_pages'])->toBe([])
        ->and(Social::hasPages('fake'))->toBeFalse();
});

it('keeps the pages through the cache as plain arrays', function () {
    $connection = new Connection('paged', true, 'active', pages: [new Page('a', Page::TYPE_PAGE, 'A')], selectedPages: ['a']);

    $copy = Connection::fromCache(unserialize(serialize($connection->toCache()), ['allowed_classes' => false]));

    expect($copy->pages)->toEqual([new Page('a', Page::TYPE_PAGE, 'A')])
        ->and($copy->selectedPages)->toBe(['a']);
});

it('selects several pages and refuses an empty or unknown choice', function () {
    $connection = Social::selectPages('paged', ['org-1', 'org-2']);

    expect($connection->selectedPages)->toBe(['org-1', 'org-2'])
        ->and(Social::selectedPages('paged'))->toBe(['org-1', 'org-2']);

    $this->network('fake');

    expect(fn () => Social::selectPages('paged', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Social::selectPages('paged', ['nope']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Social::selectPages('fake', ['me']))->toThrow(InvalidArgumentException::class);
});

it('shares to every selected page, one record per page', function () {
    Social::selectPages('paged', ['me', 'org-2']);

    $records = Social::share(pagedPost(), 'paged');

    expect($records)->toHaveCount(2)
        ->and($records->pluck('target')->all())->toBe(['me', 'org-2'])
        ->and($records->pluck('status')->unique()->all())->toBe([SharedPost::STATUS_POSTED])
        ->and(FakePagesNetwork::$postedTo)->toBe(['me', 'org-2']);
});

it('keeps sharing to the other pages when one fails, and throws only when all fail', function () {
    Social::selectPages('paged', ['me', 'org-1']);
    FakePagesNetwork::$failing = ['org-1' => 503];

    $records = Social::share(pagedPost(), 'paged');

    expect($records->pluck('status', 'target')->all())->toBe(['me' => 'posted', 'org-1' => 'failed']);

    FakePagesNetwork::$failing = ['me' => 403, 'org-1' => 503];

    expect(fn () => Social::share(pagedPost(), 'paged'))->toThrow(RequestFailed::class);
    expect(SharedPost::query()->where('status', 'failed')->count())->toBe(3);
});

it('shares to the given pages for one share without changing the selection', function () {
    $records = Social::shareToPages(pagedPost(), 'paged', ['org-1']);

    expect($records->pluck('target')->all())->toBe(['org-1'])
        ->and(Social::selectedPages('paged'))->toBe(['me']);

    expect(fn () => Social::shareToPages(pagedPost(), 'paged', ['nope']))->toThrow(InvalidArgumentException::class);
});

it('tells whether a model was posted to one page', function () {
    $post = pagedPost();
    Social::shareToPage($post, 'paged', 'org-1');

    expect(Social::wasPosted($post, 'paged', 'org-1'))->toBeTrue()
        ->and(Social::wasPosted($post, 'paged', 'org-2'))->toBeFalse()
        ->and(Social::wasPosted($post, 'paged'))->toBeTrue();
});

it('shares to the selected pages from every network at once', function () {
    $this->network('fake');
    $this->connect('fake');
    Social::selectPages('paged', ['org-1', 'org-2']);

    $records = Social::share(pagedPost());

    expect($records->map(fn (SharedPost $r) => $r->network.':'.($r->target ?? '-'))->all())
        ->toBe(['fake:-', 'paged:org-1', 'paged:org-2']);
});

it('posts to the selected pages from the queued job and never posts to a page twice on retry', function () {
    Social::selectPages('paged', ['me', 'org-1', 'org-2']);
    $post = pagedPost();
    FakePagesNetwork::$failing = ['org-2' => 503];

    $job = new PublishToSocial('post', $post->getKey(), 'paged');

    expect(fn () => $job->handle(app(SocialManager::class)))->toThrow(RequestFailed::class);
    expect(FakePagesNetwork::$postedTo)->toBe(['me', 'org-1']);

    FakePagesNetwork::$failing = [];
    $job->handle(app(SocialManager::class));

    expect(FakePagesNetwork::$postedTo)->toBe(['me', 'org-1', 'org-2'])
        ->and(SharedPost::query()->posted()->count())->toBe(3);
});

it('still shares when the target column has not been migrated', function () {
    Schema::table('social_posts', function ($table) {
        $table->dropIndex('social_posts_target_index');
        $table->dropColumn('target');
    });
    Columns::flush();

    $records = Social::share(pagedPost(), 'paged');

    expect($records)->toHaveCount(1)
        ->and($records->first()->status)->toBe(SharedPost::STATUS_POSTED);
});

it('returns the pages from the endpoint', function () {
    $this->getJson('/social/paged/pages')
        ->assertOk()
        ->assertJsonPath('data.selected_pages', ['me'])
        ->assertJsonPath('data.pages.1.id', 'org-1')
        ->assertJsonPath('data.pages.1.selected', false);
});

it('chooses the pages through the endpoint', function () {
    $this->putJson('/social/paged/pages', ['pages' => ['org-1', 'org-2']])
        ->assertOk()
        ->assertJsonPath('data.selected_pages', ['org-1', 'org-2'])
        ->assertJsonPath('data.pages.1.selected', true);

    expect(Social::selectedPages('paged'))->toBe(['org-1', 'org-2']);
});

it('answers 422 for an empty or unknown choice', function () {
    $this->putJson('/social/paged/pages', ['pages' => []])->assertStatus(422);
    $this->putJson('/social/paged/pages', ['pages' => ['nope']])->assertStatus(422)->assertJsonValidationErrors('pages');

    expect(Social::selectedPages('paged'))->toBe(['me']);
});

it('answers 404 on the pages routes for a network without pages or switched off', function () {
    $this->network('fake');
    $this->getJson('/social/fake/pages')->assertNotFound();
    $this->putJson('/social/fake/pages', ['pages' => ['x']])->assertNotFound();

    $this->network('paged', false);
    $this->getJson('/social/paged/pages')->assertNotFound();
});

it('redirects back with a message when the page form is posted', function () {
    $this->from('/admin/social')
        ->put('/social/paged/pages', ['pages' => ['org-2']])
        ->assertRedirect('/admin/social')
        ->assertSessionHas(StatusMessages::FLASH_KEY);

    expect(Social::selectedPages('paged'))->toBe(['org-2']);
});

it('overrides the pages for one share from the share endpoint', function () {
    $post = pagedPost();

    $url = URL::signedRoute('social-autopost.share.network', ['network' => 'paged', 'type' => 'post', 'id' => $post->getKey()]);

    $this->postJson($url, ['pages' => ['org-1', 'org-2']])
        ->assertCreated()
        ->assertJsonPath('data.0.page', 'org-1')
        ->assertJsonPath('data.1.page', 'org-2');

    expect(Social::selectedPages('paged'))->toBe(['me']);
});

it('adds the pages URL to the props of a network with pages only', function () {
    $this->network('fake');

    expect(SocialProps::connection('paged')['urls']['pages'])->toEndWith('/social/paged/pages')
        ->and(SocialProps::connection('fake')['urls'])->not->toHaveKey('pages');
});

it('shows a page picker on the card when there is a choice', function () {
    $html = Blade::render('<x-social-autopost::connection network="paged" />');

    expect($html)->toContain('Post to')
        ->toContain('value="org-1"')
        ->toContain('/social/paged/pages');
});

it('saves the ticked pages from the Livewire card', function () {
    Livewire::test('social-autopost.connection', ['network' => 'paged'])
        ->assertSet('selectedPages', ['me'])
        ->set('selectedPages', ['org-1'])
        ->call('selectPages')
        ->assertSee('Paged will post to the chosen pages.');

    expect(Social::selectedPages('paged'))->toBe(['org-1']);
})->skip(fn () => ! class_exists(Livewire::class), 'Livewire is not installed.');

it('lists the pages in the status command', function () {
    Social::selectPages('paged', ['org-1']);

    $this->artisan('social:status')
        ->expectsOutputToContain('Paged pages')
        ->expectsOutputToContain('[x] Org One (organization) org-1')
        ->assertSuccessful();
});

it('shares to the given pages from the share command', function () {
    $post = pagedPost();

    $this->artisan('social:share', ['network' => 'paged', 'model' => 'post', 'id' => $post->getKey(), '--page' => ['org-2']])
        ->expectsOutputToContain('Shared to org-2')
        ->assertSuccessful();

    expect(FakePagesNetwork::$postedTo)->toBe(['org-2']);
});
