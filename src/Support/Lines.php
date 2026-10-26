<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

/** The package's language lines (lang/{locale}/messages.php), always as strings. */
final class Lines
{
    /** @param  array<string, string|int>  $replace */
    public static function get(string $key, array $replace = []): string
    {
        $line = __('social-autopost::messages.'.$key, $replace);

        return is_string($line) ? $line : "social-autopost::messages.{$key}";
    }
}
