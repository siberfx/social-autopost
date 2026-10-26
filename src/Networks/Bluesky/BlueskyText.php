<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Bluesky;

use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * Turns a LinkPost into the text of one Bluesky post, within the 300-character
 * limit. The link travels in the post's external embed card, so the default
 * layout is the title and as much of the description as fits — no URL eating
 * into the room. A custom commentary is used as written when it fits.
 */
final class BlueskyText
{
    public const LIMIT = 300;

    public static function compose(LinkPost $post): string
    {
        $title = $post->title();
        $url = $post->url();
        $custom = $post->commentaryText();

        if ($custom !== $title."\n\n".$url && mb_strlen($custom) <= self::LIMIT) {
            return $custom;
        }

        $description = trim($post->descriptionText());
        $body = self::fit($description, self::LIMIT - mb_strlen($title) - 2);

        return $body === '' ? self::fit($title, self::LIMIT) : $title."\n\n".$body;
    }

    /**
     * The link facets for every occurrence of the URL in the text, as byte
     * slices, so Bluesky shows them as links. Empty when the text has none.
     *
     * @return list<array<string, mixed>>
     */
    public static function facets(string $text, string $url): array
    {
        $facets = [];
        $offset = 0;

        while (($start = strpos($text, $url, $offset)) !== false) {
            $facets[] = [
                'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($url)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
            ];
            $offset = $start + strlen($url);
        }

        return $facets;
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
