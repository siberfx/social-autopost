<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

final class Theme
{
    public static function name(): string
    {
        $theme = config('social-autopost.ui.theme', 'tailwind');

        return is_string($theme) && $theme !== '' ? $theme : 'tailwind';
    }

    /** @return view-string */
    public static function view(string $name): string
    {
        /** @var view-string $view */
        $view = sprintf('social-autopost::%s.%s', self::name(), $name);

        return $view;
    }
}
