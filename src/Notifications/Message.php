<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications;

use Illuminate\Database\Eloquent\Model;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Events\Shared;
use Siberfx\SocialAutopost\Events\ShareFailed;
use Siberfx\SocialAutopost\Events\TokenExpiringSoon;
use Throwable;

/**
 * Plain text a channel formats its own way: a headline and lines below it.
 * Holds only strings, so it queues cleanly. Every headline names the network.
 */
final class Message
{
    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $level,
        public readonly string $headline,
        public readonly array $lines = [],
    ) {}

    /** @param  string|null  $postUrl  Link to the post on the network, when the network has one. */
    public static function shared(Shared $event, string $label, ?string $postUrl = null): self
    {
        [$title, $url] = self::describe($event->shareable);

        return new self(self::LEVEL_SUCCESS, "Shared on {$label}: ".$title, array_values(array_filter([
            $url,
            $postUrl !== null && $event->post->post_id !== null ? 'Post: '.$postUrl : null,
            'Trigger: '.$event->post->trigger,
        ])));
    }

    public static function failed(ShareFailed $event, string $label): self
    {
        return new self(self::LEVEL_ERROR, "{$label} share failed", [
            "Model: {$event->shareableType} #{$event->shareableId}",
            'Error: '.mb_substr($event->exception->getMessage(), 0, 500),
        ]);
    }

    public static function tokenExpiring(TokenExpiringSoon $event, string $label): self
    {
        $headline = $event->daysLeft > 0
            ? "The {$label} token expires in {$event->daysLeft} days"
            : "The {$label} token has expired";

        return new self(self::LEVEL_WARNING, $headline, array_values(array_filter([
            $event->connection->name !== null ? 'Account: '.$event->connection->name : null,
            'Connect again to keep posting.',
        ])));
    }

    public static function test(): self
    {
        return new self(self::LEVEL_INFO, 'Social Autopost test notification', [
            'Notifications from '.config('app.name', 'Laravel').' reach this channel.',
        ]);
    }

    public function emoji(): string
    {
        return match ($this->level) {
            self::LEVEL_SUCCESS => '✅',
            self::LEVEL_ERROR => '❌',
            self::LEVEL_WARNING => '⚠️',
            default => 'ℹ️',
        };
    }

    /** @return array{0: string, 1: string|null} The title and URL the model posts, or its type and key. */
    private static function describe(Model $model): array
    {
        if ($model instanceof ShareableOnSocial) {
            try {
                $post = $model->toSocialPost();

                return [$post->title(), $post->url()];
            } catch (Throwable) {
                // Fall through: the post already went out, the message still should.
            }
        }

        return [$model->getMorphClass().' #'.$model->getKey(), null];
    }
}
