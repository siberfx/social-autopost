<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Siberfx\SocialAutopost\Jobs\PublishToSocial;
use Siberfx\SocialAutopost\Models\SharedPost;

function retryRow(string $status, int|string $id = 1, string $network = 'fake', ?string $target = null, ?string $createdAt = null): SharedPost
{
    $row = SharedPost::query()->create([
        'shareable_type' => 'post',
        'shareable_id' => $id,
        'network' => $network,
        'target' => $target,
        'status' => $status,
        'trigger' => SharedPost::TRIGGER_AUTO,
        'error' => $status === SharedPost::STATUS_FAILED ? 'boom' : null,
        'posted_at' => $status === SharedPost::STATUS_POSTED ? now() : null,
    ]);

    if ($createdAt !== null) {
        $row->timestamps = false;
        $row->forceFill(['created_at' => $createdAt])->save();
    }

    return $row;
}

beforeEach(function () {
    Bus::fake();
    $this->network('fake');
    $this->connect('fake');
});

it('queues each failed share that never posted, once', function () {
    retryRow(SharedPost::STATUS_FAILED);
    retryRow(SharedPost::STATUS_FAILED); // a second failure of the same share
    retryRow(SharedPost::STATUS_FAILED, id: 2);

    $this->artisan('social:retry')->assertSuccessful();

    Bus::assertDispatchedTimes(PublishToSocial::class, 2);
    Bus::assertDispatched(PublishToSocial::class, fn (PublishToSocial $job) => $job->shareableId == 1
        && $job->network === 'fake' && $job->trigger === SharedPost::TRIGGER_CONSOLE);
});

it('leaves a failure alone once the model posted there', function () {
    retryRow(SharedPost::STATUS_FAILED);
    retryRow(SharedPost::STATUS_POSTED);

    $this->artisan('social:retry')->expectsOutputToContain('Nothing to retry');

    Bus::assertNotDispatched(PublishToSocial::class);
});

it('still retries a page that failed when another page of the same share posted', function () {
    retryRow(SharedPost::STATUS_FAILED, target: 'page-a');
    retryRow(SharedPost::STATUS_POSTED, target: 'page-b');

    $this->artisan('social:retry')->assertSuccessful();

    Bus::assertDispatchedTimes(PublishToSocial::class, 1);
});

it('skips a network that is switched off or not connected', function () {
    retryRow(SharedPost::STATUS_FAILED, network: 'broken'); // enabled in setUp? no: off

    $this->artisan('social:retry')->expectsOutputToContain('Nothing to retry');

    Bus::assertNotDispatched(PublishToSocial::class);
});

it('filters by network and by --since, and --dry-run queues nothing', function () {
    retryRow(SharedPost::STATUS_FAILED, id: 1, createdAt: '2026-01-01 00:00:00');
    retryRow(SharedPost::STATUS_FAILED, id: 2);

    $this->artisan('social:retry', ['network' => 'fake', '--since' => now()->subDay()->toDateString(), '--dry-run' => true])
        ->expectsOutputToContain('would be queued')
        ->assertSuccessful();

    Bus::assertNotDispatched(PublishToSocial::class);
});
