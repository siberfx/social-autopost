<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\SocialAutopost\Notifications\Message;
use Siberfx\SocialAutopost\Notifications\Notifier;
use Throwable;

final class TestNotificationCommand extends Command
{
    protected $signature = 'social:test-notification {--channel=* : Only these channels (default: every enabled one)}';

    protected $description = 'Send a test message to the enabled notification channels (Telegram, Slack, email)';

    public function handle(Notifier $notifier): int
    {
        /** @var list<string> $only */
        $only = (array) $this->option('channel');
        $channels = $only !== [] ? $only : $notifier->enabledChannels();

        if ($channels === []) {
            $this->components->warn('No notification channel is enabled. Set SOCIAL_NOTIFY_TELEGRAM=true or SOCIAL_NOTIFY_SLACK=true.');

            return self::FAILURE;
        }

        if (! $notifier->enabled()) {
            $this->components->warn('Notifications are off (SOCIAL_NOTIFY=false); sending the test anyway.');
        }

        $failed = false;

        foreach ($channels as $name) {
            try {
                $channel = $notifier->channel($name);

                if (! $channel->configured()) {
                    $this->components->error("{$name}: missing settings, see notifications.channels.{$name} in config/social-autopost.php.");
                    $failed = true;

                    continue;
                }

                $channel->send(Message::test());
                $this->components->info("{$name}: sent.");
            } catch (Throwable $e) {
                $this->components->error("{$name}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
