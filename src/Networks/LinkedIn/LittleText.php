<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

/**
 * LinkedIn's "little" text format reserves characters for mentions, hashtags
 * and templates. Escaping them makes commentary appear exactly as written.
 */
final class LittleText
{
    /** @var list<string> */
    public const RESERVED = ['|', '{', '}', '@', '[', ']', '(', ')', '<', '>', '#', '\\', '*', '_', '~'];

    /**
     * Escape reserved characters and keep the result within $limit
     * characters, never cutting an escape sequence in half.
     */
    public static function escape(string $text, int $limit = 3000): string
    {
        $out = '';
        $length = 0;

        foreach (mb_str_split($text) as $char) {
            $piece = in_array($char, self::RESERVED, true) ? '\\'.$char : $char;
            $pieceLength = mb_strlen($piece);

            if ($length + $pieceLength > $limit) {
                break;
            }

            $out .= $piece;
            $length += $pieceLength;
        }

        return $out;
    }
}
