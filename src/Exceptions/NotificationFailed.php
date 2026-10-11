<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

use RuntimeException;

/** A notification channel could not deliver a message. Never carries the channel's secrets. */
final class NotificationFailed extends RuntimeException
{
    /** Removes secrets (bot token, webhook URL) that HTTP client errors may repeat. */
    public static function redacted(string $message, string ...$secrets): self
    {
        $secrets = array_values(array_filter($secrets, fn (string $secret) => $secret !== ''));

        return new self(str_replace($secrets, '***', $message));
    }
}
