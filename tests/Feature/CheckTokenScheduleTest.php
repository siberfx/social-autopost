<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/** The token check events on a freshly resolved schedule. */
function scheduledTokenChecks(): array
{
    app()->forgetInstance(Schedule::class);

    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event) => str_contains((string) $event->command, 'social:check-token'),
    ));
}

it('schedules social:check-token on the configured cron expression', function () {
    config()->set('social-autopost.check_token_schedule', '30 7 * * 1');

    $events = scheduledTokenChecks();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 7 * * 1');
});

it('schedules nothing when check_token_schedule is null', function () {
    config()->set('social-autopost.check_token_schedule', null);

    expect(scheduledTokenChecks())->toBe([]);
});
