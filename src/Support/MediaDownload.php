<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;

/**
 * Downloads a post's media, so a network that takes bytes (LinkedIn, X) can
 * upload it. Facebook, Instagram and TikTok download from the URL themselves.
 * A local file path is read from disk instead, for apps that call the
 * network clients directly with media they keep on their own server.
 */
final class MediaDownload
{
    /**
     * @param  string  $url  An http(s) URL, or the path of a local file.
     * @return array{0: string, 1: string} The bytes and their content type.
     *
     * @throws RequestFailed Retryable when the media host answers 5xx or not at
     *                       all; never a 401, which would read as an expired connection.
     */
    public static function fetch(string $url, string $network): array
    {
        if (! preg_match('~^https?://~i', $url)) {
            return self::read($url, $network);
        }

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

    /**
     * @return array{0: string, 1: string}
     *
     * @throws RequestFailed (422) When the file is missing or unreadable: retrying cannot help.
     */
    private static function read(string $path, string $network): array
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($bytes === false) {
            throw new RequestFailed("Could not read the media for {$network}: no readable file at {$path}.", 422);
        }

        return [$bytes, mime_content_type($path) ?: 'application/octet-stream'];
    }
}
