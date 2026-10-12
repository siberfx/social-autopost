<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\TikTok;

use Illuminate\Http\Client\Response;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;

/**
 * Turns a failed TikTok response into the package's RequestFailed, with
 * TikTok's own reason in the message. TikTok may answer 200 with an error in
 * the body, so the status comes from the error code where it says more.
 */
final class TikTokFailure
{
    /** TikTok error codes and the HTTP status the package treats them as. */
    private const STATUS = [
        'access_token_invalid' => 401,
        'invalid_grant' => 401,
        'scope_not_authorized' => 403,
        'rate_limit_exceeded' => 429,
        'internal_error' => 500,
    ];

    /** Whether the response carries an error, even with a 2xx status. */
    public static function failed(Response $response): bool
    {
        if ($response->failed()) {
            return true;
        }

        $error = $response->json('error');

        return is_array($error) ? ($error['code'] ?? 'ok') !== 'ok' : is_string($error) && $error !== '';
    }

    public static function from(Response $response): RequestFailed
    {
        $error = $response->json('error');

        // The Content Posting API nests {code, message}; the OAuth endpoints answer {error, error_description}.
        $code = is_array($error) ? ($error['code'] ?? null) : $error;
        $reason = is_array($error) ? ($error['message'] ?? null) : $response->json('error_description');

        $reason = is_string($reason) && $reason !== '' ? $reason : (is_string($code) && $code !== '' ? $code : 'HTTP '.$response->status());
        $status = (is_string($code) ? self::STATUS[$code] ?? null : null) ?? ($response->failed() ? $response->status() : 400);

        return new RequestFailed('TikTok rejected the request: '.$reason, $status);
    }
}
