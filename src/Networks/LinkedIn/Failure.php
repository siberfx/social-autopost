<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

use Illuminate\Http\Client\Response;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;

/** Turns a refused LinkedIn response into the RequestFailed the core reads for retries and 401s. */
final class Failure
{
    public static function fromResponse(Response $response): RequestFailed
    {
        $reason = $response->json('message')
            ?? $response->json('error_description')
            ?? $response->json('error');

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();

        return new RequestFailed('LinkedIn rejected the request: '.$reason, $response->status());
    }
}
