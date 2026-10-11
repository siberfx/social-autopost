<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

/**
 * The colour and logo a network is shown with. The colour comes from
 * networks.{key}.brand.color in the config; the logo from the built-in set,
 * or networks.{key}.brand.logo for your own network (inline SVG markup).
 */
final class NetworkBrand
{
    private const FALLBACK_COLOR = '#6b7280';

    private const LOGOS = [
        'linkedin' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.95v5.66H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z"/></svg>',
        'x' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
    ];

    private const GENERIC_LOGO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>';

    public static function color(string $key): string
    {
        $color = config("social-autopost.networks.{$key}.brand.color");

        return is_string($color) && $color !== '' ? $color : self::FALLBACK_COLOR;
    }

    public static function logo(string $key): string
    {
        $custom = config("social-autopost.networks.{$key}.brand.logo");

        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        return self::LOGOS[$key] ?? self::GENERIC_LOGO;
    }

    /** @return array{color: string, logo: string} */
    public static function for(string $key): array
    {
        return ['color' => self::color($key), 'logo' => self::logo($key)];
    }
}
