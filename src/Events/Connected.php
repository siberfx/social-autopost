<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Events;

use Siberfx\SocialAutopost\Data\Connection;

final class Connected
{
    public function __construct(
        public readonly string $network,
        public readonly Connection $connection,
    ) {}
}
