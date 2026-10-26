<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;

/**
 * Downloads a post's media, so a network that takes bytes (LinkedIn, X) can
 * upload it. Facebook, Instagram and TikTok download from the URL themselves.
 */
final class MediaDownload
{
    /**
     * @return array{0: string, 1: string} The bytes and their content type.
     *
     * @throws RequestFailed Retryable when the media host answers 5xx or not at
     *                       all; never a 401, which would read as an expired connection.
     */
    public static function fetch(string $url, string $network): array
    {
        try {
            $response = Http::timeout((int) config('social-autopost.http.timeout', 20))->get($url);
        } catch (ConnectionException $e) {
            throw new RequestFailed("Could not download the media for {$network}: ".$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw new RequestFailed(
                "Could not download the media for {$network}: HTTP ".$response->status()." from {$url}.",
                $response->serverError() ? 0 : 422,
            );
        }

        $type = (string) $response->header('Content-Type');
        $type = strtolower(trim(explode(';', $type !== '' ? $type : 'application/octet-stream')[0]));

        return [$response->body(), $type];
    }
}
