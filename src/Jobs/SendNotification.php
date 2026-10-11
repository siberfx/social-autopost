<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Siberfx\SocialAutopost\Notifications\Message;
use Siberfx\SocialAutopost\Notifications\Notifier;

/** Delivers one message to one channel; a failure is retried, then lands in failed_jobs. */
final class SendNotification implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public function __construct(
        public readonly string $channel,
        public readonly Message $message,
    ) {
        $this->tries = (int) config('social-autopost.notifications.tries', 3);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('social-autopost.notifications.backoff', [30, 120])));
    }

    public function handle(Notifier $notifier): void
    {
        // Switched off since the job was queued: drop it.
        if (! $notifier->enabled() || ! in_array($this->channel, $notifier->enabledChannels(), true)) {
            return;
        }

        $notifier->sendNow($this->channel, $this->message);
    }
}
