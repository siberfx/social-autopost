<?php

declare(strict_types=1);

use Siberfx\SocialAutopost\Models\SharedPost;

function pruneRecord(string $network, int $daysAgo): SharedPost
{
    $record = SharedPost::query()->create([
        'shareable_type' => 'post',
        'shareable_id' => 1,
        'network' => $network,
        'status' => SharedPost::STATUS_POSTED,
        'trigger' => SharedPost::TRIGGER_MANUAL,
        'posted_at' => now()->subDays($daysAgo),
    ]);

    $record->timestamps = false;
    $record->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

    return $record;
}

it('prunes nothing until prune_after_days is set', function () {
    pruneRecord('fake', 400);

    $this->artisan('model:prune', ['--model' => SharedPost::class]);

    expect(SharedPost::query()->count())->toBe(1);
});

it('prunes history older than prune_after_days and keeps the rest', function () {
    config()->set('social-autopost.prune_after_days', 90);

    pruneRecord('fake', 91);
    $kept = pruneRecord('fake', 89);

    $this->artisan('model:prune', ['--model' => SharedPost::class]);

    expect(SharedPost::query()->pluck('id')->all())->toBe([$kept->id]);
});
