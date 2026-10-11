<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Jobs\PublishToSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Tests\Fixtures\FailingNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\FakeNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

/** Queues one publish job per network that is enabled, auto-posting, connected and not posted yet. */
it('queues an auto-post only when enabled, autopost, connected and not posted', function (bool $enabled, bool $autopost, bool $connected, bool $posted, bool $expected) {
    Bus::fake();

    $this->network('fake', $enabled, $autopost);
    if ($connected) {
        $this->connect('fake');
    }

    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);

    if ($posted) {
        SharedPost::query()->create([
            'shareable_type' => $post->getMorphClass(),
            'shareable_id' => $post->getKey(),
            'network' => 'fake',
            'status' => SharedPost::STATUS_POSTED,
            'trigger' => SharedPost::TRIGGER_MANUAL,
            'posted_at' => now(),
        ]);
    }

    $post->update(['status' => 'published']);

    $dispatched = Bus::dispatched(PublishToSocial::class, fn (PublishToSocial $job) => $job->network === 'fake');

    expect($dispatched)->toHaveCount($expected ? 1 : 0);
})->with([
    'enabled, autopost, connected' => [true, true, true, false, true],
    'autopost off' => [true, false, true, false, false],
    'switched off' => [false, true, true, false, false],
    'not connected' => [true, true, false, false, false],
    'already posted there' => [true, true, true, true, false],
]);

it('does not auto-post from the console unless allowed', function () {
    Bus::fake();
    config()->set('social-autopost.autopost.in_console', false);

    $this->network('fake', true, true);
    $this->connect('fake');

    Post::create(['title' => 'Hello', 'status' => 'published']);

    Bus::assertNotDispatched(PublishToSocial::class);
});

it('posts a queued job when it runs and records the post', function () {
    $this->network('fake', true, true);
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    (new PublishToSocial($post->getMorphClass(), $post->getKey(), 'fake'))->handle(app(SocialManager::class));

    expect(FakeNetwork::$published)->toHaveCount(1);
    expect(Social::wasPosted($post, 'fake'))->toBeTrue();
    expect(SharedPost::query()->first()->trigger)->toBe(SharedPost::TRIGGER_AUTO);
});

it('lets a retryable network failure go back to the queue', function () {
    $this->network('broken', true, true);
    $this->connect('broken');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    FailingNetwork::$status = 503;

    (new PublishToSocial($post->getMorphClass(), $post->getKey(), 'broken'))
        ->handle(app(SocialManager::class));
})->throws(RequestFailed::class);
