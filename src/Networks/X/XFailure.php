<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

use Illuminate\Http\Client\Response;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;

/** Turns a failed X response into the package's RequestFailed, with X's own reason in the message. */
final class XFailure
{
    public static function from(Response $response): RequestFailed
    {
        $reason = $response->json('detail')
            ?? $response->json('message')
            ?? $response->json('error_description')
            ?? $response->json('error');

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();

        return new RequestFailed('X rejected the request: '.$reason, $response->status());
    }
}
