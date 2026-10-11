<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

use RuntimeException;

/**
 * Connecting failed. $state is the short code the callback shows the admin
 * (cancelled, error, exchange_failed, invalid_state, missing_code, not_configured);
 * the message is safe to show.
 */
class ConnectionFailed extends RuntimeException
{
    public function __construct(
        public readonly string $state,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
