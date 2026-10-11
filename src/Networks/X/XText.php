<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * Turns a LinkPost into the text of one X post, within X's 280-character limit.
 *
 * X counts every URL as 23 characters, whatever its length, so the link is
 * weighed that way. Two layouts:
 *
 * - Default (no commentary set, or the default "{title}\n\n{url}"): the title,
 *   then as much of the description as fits, then the link. The description
 *   is cut with an ellipsis, so the title and link always survive.
 * - Custom commentary: used as written when it fits. When it does not fit,
 *   the default layout is used instead, so a long template never breaks a post.
 */
final class XText
{
    public const LIMIT = 280;

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

    /** Length as X counts it: every occurrence of the URL is 23 characters. */
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
