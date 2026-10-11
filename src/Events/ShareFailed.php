<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Events;

use Throwable;

/** An automatic share to one network gave up after its last attempt. */
final class ShareFailed
{
    public function __construct(
        public readonly string $network,
        public readonly string $shareableType,
        public readonly int|string $shareableId,
        public readonly Throwable $exception,
    ) {}
}
