<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Events;

use Siberfx\SocialAutopost\Data\Connection;

final class TokenExpiringSoon
{
    /** @param  int  $daysLeft  Negative once the token has expired. */
    public function __construct(
        public readonly string $network,
        public readonly Connection $connection,
        public readonly int $daysLeft,
    ) {}
}
