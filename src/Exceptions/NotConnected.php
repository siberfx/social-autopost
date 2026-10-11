<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

use RuntimeException;

final class NotConnected extends RuntimeException
{
    public function __construct(string $message = 'That network is not connected.')
    {
        parent::__construct($message);
    }
}
