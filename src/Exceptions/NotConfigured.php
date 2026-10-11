<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Exceptions;

/** The network's client id, secret or app settings are missing. */
final class NotConfigured extends ConnectionFailed
{
    public function __construct(string $message = 'The network is not configured: add its client id and secret to .env.')
    {
        parent::__construct('not_configured', $message);
    }
}
