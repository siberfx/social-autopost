<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Support\MediaDownload;

/** Uploads a post's image to X (POST /2/media/upload), so the post can carry it. */
final class MediaClient
{
    private const URL = 'https://api.x.com/2/media/upload';

    /**
     * Download the image and upload it for the account. Returns the media id
     * the post carries. Needs the media.write scope.
     *
     * @throws RequestFailed
     */
    public function upload(string $accessToken, string $imageUrl): string
    {
        [$binary] = MediaDownload::fetch($imageUrl, 'X');

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('social-autopost.http.upload_timeout', 120))
                ->acceptJson()
                ->attach('media', $binary, 'media')
                ->post(self::URL, ['media_category' => 'tweet_image']);
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach X: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw XFailure::from($response);
        }

        $id = $response->json('data.id');

        return is_string($id) && $id !== ''
            ? $id
            : throw new RequestFailed('X returned no media id for the image.', $response->status());
    }
}
