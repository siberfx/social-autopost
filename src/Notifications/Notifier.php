<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\NotificationChannel;
use Siberfx\SocialAutopost\Exceptions\NotificationFailed;
use Siberfx\SocialAutopost\Jobs\SendNotification;
use Throwable;

/** Builds the channels in social-autopost.notifications.channels and sends to them. */
final class Notifier
{
    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $bus,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('social-autopost.notifications.enabled', false);
    }

    /** Whether notifications are on and this event (shared, failed, token_expiring) is wanted. */
    public function wants(string $event): bool
    {
        return $this->enabled() && (bool) config("social-autopost.notifications.events.{$event}", true);
    }

    /** @return list<string> Channels switched on in the config, configured or not. */
    public function enabledChannels(): array
    {
        $names = [];

        foreach ($this->channelsConfig() as $name => $config) {
            if ((bool) ($config['enabled'] ?? false)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** @throws InvalidArgumentException When the channel is not in the config or its class is not a NotificationChannel. */
    public function channel(string $name): NotificationChannel
    {
        $config = $this->channelsConfig()[$name] ?? throw new InvalidArgumentException("No notification channel [{$name}] is configured.");
        $class = $config['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, NotificationChannel::class)) {
            throw new InvalidArgumentException("The [{$name}] notification channel needs a class implementing ".NotificationChannel::class.'.');
        }

        /** @var NotificationChannel */
        return $this->container->make($class, ['config' => $config]);
    }

    public function queued(): bool
    {
        return (bool) config('social-autopost.notifications.queued', true);
    }

    /**
     * Sends the message to every enabled, configured channel: one queued job
     * each so they retry independently, or right away when `queued` is false.
     * Never throws: a share must not fail over a notification.
     */
    public function notify(Message $message): void
    {
        foreach ($this->enabledChannels() as $name) {
            try {
                $channel = $this->channel($name);

                if (! $channel->configured()) {
                    continue;
                }

                if (! $this->queued()) {
                    $channel->send($message);

                    continue;
                }

                $this->bus->dispatch((new SendNotification($name, $message))
                    ->onConnection(config('social-autopost.notifications.queue_connection'))
                    ->onQueue(config('social-autopost.notifications.queue')));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Sends right away, for social:test-notification and the queued job.
     *
     * @throws NotificationFailed|InvalidArgumentException
     */
    public function sendNow(string $name, Message $message): void
    {
        $this->channel($name)->send($message);
    }

    /** @return array<array-key, array<string, mixed>> */
    private function channelsConfig(): array
    {
        $channels = config('social-autopost.notifications.channels', []);

        return is_array($channels) ? array_filter($channels, 'is_array') : [];
    }
}
