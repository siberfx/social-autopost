<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

use RuntimeException;

/** The network is switched off (its "enabled" flag is false). */
final class NetworkDisabled extends RuntimeException
{
    public function __construct(string $network)
    {
        parent::__construct("The [{$network}] network is switched off. Set its ENABLED flag in .env to turn it on.");
    }
}
