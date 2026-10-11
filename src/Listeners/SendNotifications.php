<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Listeners;

use Siberfx\SocialAutopost\Events\Shared;
use Siberfx\SocialAutopost\Events\ShareFailed;
use Siberfx\SocialAutopost\Events\TokenExpiringSoon;
use Siberfx\SocialAutopost\Notifications\Message;
use Siberfx\SocialAutopost\Notifications\Notifier;
use Siberfx\SocialAutopost\SocialManager;
use Throwable;

/** Turns the package events into Telegram/Slack/email messages when social-autopost.notifications says so. */
final class SendNotifications
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly SocialManager $social,
    ) {}

    public function handle(Shared|ShareFailed|TokenExpiringSoon $event): void
    {
        $key = match (true) {
            $event instanceof Shared => 'shared',
            $event instanceof ShareFailed => 'failed',
            $event instanceof TokenExpiringSoon => 'token_expiring',
        };

        if (! $this->notifier->wants($key)) {
            return;
        }

        try {
            $label = $this->social->network($event->network)->label();

            $message = match (true) {
                $event instanceof Shared => Message::shared($event, $label, $this->postUrl($event)),
                $event instanceof ShareFailed => Message::failed($event, $label),
                $event instanceof TokenExpiringSoon => Message::tokenExpiring($event, $label),
            };
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->notifier->notify($message);
    }

    /** The network's post_url template with the post id filled in, or null when it has none. */
    private function postUrl(Shared $event): ?string
    {
        $template = config("social-autopost.networks.{$event->network}.post_url");

        if (! is_string($template) || $event->post->post_id === null) {
            return null;
        }

        return str_replace('{id}', $event->post->post_id, $template);
    }
}
