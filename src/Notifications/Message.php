<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications;

use Illuminate\Database\Eloquent\Model;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Events\Shared;
use Siberfx\SocialAutopost\Events\ShareFailed;
use Siberfx\SocialAutopost\Events\TokenExpiringSoon;
use Siberfx\SocialAutopost\Support\Lines;
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

        return new self(self::LEVEL_SUCCESS, Lines::get('notify_shared', ['label' => $label, 'title' => $title]), array_values(array_filter([
            $url,
            $postUrl !== null && $event->post->post_id !== null ? Lines::get('notify_post', ['url' => $postUrl]) : null,
            Lines::get('notify_trigger', ['trigger' => $event->post->trigger]),
        ])));
    }

    public static function failed(ShareFailed $event, string $label): self
    {
        return new self(self::LEVEL_ERROR, Lines::get('notify_failed', ['label' => $label]), [
            Lines::get('notify_model', ['type' => $event->shareableType, 'id' => $event->shareableId]),
            Lines::get('notify_error', ['error' => mb_substr($event->exception->getMessage(), 0, 500)]),
        ]);
    }

    public static function tokenExpiring(TokenExpiringSoon $event, string $label): self
    {
        $headline = $event->daysLeft > 0
            ? Lines::get('notify_expires', ['label' => $label, 'days' => $event->daysLeft])
            : Lines::get('notify_expired', ['label' => $label]);

        return new self(self::LEVEL_WARNING, $headline, array_values(array_filter([
            $event->connection->name !== null ? Lines::get('notify_account', ['name' => $event->connection->name]) : null,
            Lines::get('notify_reconnect'),
        ])));
    }

    public static function test(): self
    {
        return new self(self::LEVEL_INFO, Lines::get('notify_test'), [
            Lines::get('notify_test_line', ['app' => config('app.name', 'Laravel')]),
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
