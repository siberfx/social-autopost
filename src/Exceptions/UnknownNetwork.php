<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

use InvalidArgumentException;

/** No network with that key is listed in config('social-autopost.networks'). */
final class UnknownNetwork extends InvalidArgumentException
{
    public function __construct(string $network)
    {
        parent::__construct("No social network [{$network}] is configured in config/social-autopost.php.");
    }
}
