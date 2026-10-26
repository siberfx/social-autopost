<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Siberfx\SocialAutopost\Events\Shared;
use Siberfx\SocialAutopost\Exceptions\NotShareable;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Tests\Fixtures\FailingNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\FakeNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

it('records one share per network it posts to', function () {
    Event::fake([Shared::class]);

    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    $records = Social::share($post);

    expect($records)->toHaveCount(1);
    expect($records->first()->status)->toBe(SharedPost::STATUS_POSTED);
    expect($records->first()->post_id)->toStartWith('fake-');
    expect($post->wasPostedTo('fake'))->toBeTrue();
    expect($post->wasPostedToSocial())->toBeTrue();
    Event::assertDispatched(Shared::class, fn (Shared $event) => $event->network === 'fake');
});

it('keeps the other networks going when one fails, and records the failure', function () {
    $this->network('fake');
    $this->network('broken');
    $this->connect('fake');
    $this->connect('broken');
    FailingNetwork::$status = 503;

    $post = Post::create(['title' => 'Hello', 'status' => 'published']);
    $records = Social::share($post)->keyBy('network');

    expect($records['fake']->status)->toBe(SharedPost::STATUS_POSTED);
    expect($records['broken']->status)->toBe(SharedPost::STATUS_FAILED);
    expect($records['broken']->error)->toContain('Broken refused the post.');
    expect($post->wasPostedTo('broken'))->toBeFalse();
    expect($post->wasPostedTo('fake'))->toBeTrue();
});

it('rethrows a failure when one network is named, after recording it', function () {
    $this->network('broken');
    $this->connect('broken');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    expect(fn () => Social::share($post, 'broken'))->toThrow(RequestFailed::class);

    expect(SharedPost::query()->where('network', 'broken')->value('status'))->toBe(SharedPost::STATUS_FAILED);
});

it('does not share a model that is not live', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Draft', 'status' => 'draft']);

    Social::share($post);
})->throws(NotShareable::class);

it('posts the same model again on request', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::share($post);
    Social::share($post);

    expect(FakeNetwork::$published)->toHaveCount(2);
    expect(SharedPost::query()->posted()->count())->toBe(2);
});

it('builds the link post the network receives from toSocialPost()', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::share($post);

    expect(FakeNetwork::$published[0])->toMatchArray([
        'url' => "https://example.test/posts/{$post->getKey()}",
        'title' => 'Hello',
        'description' => 'A short summary.',
    ]);
});

it('publishes the post tailored with on() for that network', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::shareTo($post, 'fake', SharedPost::TRIGGER_MANUAL,
        LinkPost::make('https://example.com/a', 'Hello')
            ->on('fake', fn ($tailored) => $tailored->commentary('Tailored for fake'))
            ->on('other', fn ($tailored) => $tailored->commentary('Not this one')));

    expect(FakeNetwork::$published)->toHaveCount(1)
        ->and(FakeNetwork::$published[0]['commentary'])->toBe('Tailored for fake');
});
