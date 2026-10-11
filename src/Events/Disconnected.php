<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Events;

final class Disconnected
{
    /** @param  bool  $revoked  Whether the network confirmed revoking the connection. */
    public function __construct(
        public readonly string $network,
        public readonly bool $revoked,
    ) {}
}
