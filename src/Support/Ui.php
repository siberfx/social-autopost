<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

final class Ui
{
    /** False in headless mode: no views or components, JSON-only responses. */
    public static function enabled(): bool
    {
        return (bool) config('social-autopost.ui.enabled', true);
    }
}
