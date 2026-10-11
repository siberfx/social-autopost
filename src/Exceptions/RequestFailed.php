<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

use RuntimeException;

/** A network refused or could not be reached. Drivers throw it with a user-safe message and the HTTP status (0 when unreachable). */
final class RequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /** Worth sending again: rate limited, server errors, or no answer at all. A 4xx other than 429 never succeeds on retry. */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
