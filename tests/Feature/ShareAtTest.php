<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Jobs\PublishToSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Tests\Fixtures\FakeNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

it('queues a scheduled share with its delay and the scheduled trigger', function () {
    Bus::fake();
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);
    $when = now()->addDay();

    Social::shareAt($post, $when, 'fake');

    Bus::assertDispatched(PublishToSocial::class, fn (PublishToSocial $job) => $job->network === 'fake'
        && $job->trigger === SharedPost::TRIGGER_SCHEDULED
        && $job->delay?->getTimestamp() === $when->timestamp);
});

it('schedules one job per enabled, connected network when no network is named', function () {
    Bus::fake();
    $this->network('fake');
    $this->connect('fake');
    $this->network('paged');
    $this->connect('paged');
    $this->network('broken'); // enabled but not connected
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::shareAt($post, now()->addHour());

    Bus::assertDispatchedTimes(PublishToSocial::class, 2);
    Bus::assertNotDispatched(PublishToSocial::class, fn (PublishToSocial $job) => $job->network === 'broken');
});

it('refuses to schedule a share to a switched-off network', function () {
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::shareAt($post, now()->addHour(), 'fake');
})->throws(NetworkDisabled::class);

it('records a scheduled share with the scheduled trigger when the job runs', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    (new PublishToSocial($post->getMorphClass(), $post->getKey(), 'fake', SharedPost::TRIGGER_SCHEDULED))
        ->handle(app(SocialManager::class));

    expect(FakeNetwork::$published)->toHaveCount(1)
        ->and(SharedPost::query()->sole()->trigger)->toBe(SharedPost::TRIGGER_SCHEDULED);
});
