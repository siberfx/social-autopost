<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Mastodon;

use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * Turns a LinkPost into the text of one Mastodon status, within the default
 * 500-character limit. Mastodon counts every URL as 23 characters, like X,
 * so the same two layouts apply: a custom commentary when it fits, or the
 * title, as much of the description as fits, and the link.
 */
final class MastodonText
{
    public const LIMIT = 500;

    public const URL_LENGTH = 23;

    /** Two newlines, counted before and after the description. */
    private const SEPARATORS = 4;

    public static function compose(LinkPost $post): string
    {
        $title = $post->title();
        $url = $post->url();
        $custom = $post->commentaryText();

        if ($custom !== $title."\n\n".$url && self::weigh($custom, $url) <= self::LIMIT) {
            return $custom;
        }

        $description = trim($post->descriptionText());
        $room = self::LIMIT - self::URL_LENGTH - self::SEPARATORS - mb_strlen($title);
        $body = self::fit($description, $room);

        return $body === ''
            ? $title."\n\n".$url
            : $title."\n\n".$body."\n\n".$url;
    }

    /** Length as Mastodon counts it: every occurrence of the URL is 23 characters. */
    public static function weigh(string $text, string $url): int
    {
        return mb_strlen(str_replace($url, str_repeat('x', self::URL_LENGTH), $text));
    }

    /** Cut to at most $max characters, ending in an ellipsis when cut. */
    private static function fit(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
