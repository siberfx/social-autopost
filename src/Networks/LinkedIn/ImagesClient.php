<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Support\MediaDownload;

/** Uploads a post's image to LinkedIn's Images API, so the post can carry it. */
final class ImagesClient
{
    private const INITIALIZE = 'https://api.linkedin.com/rest/images?action=initializeUpload';

    /**
     * Initialize the upload for the author, download the image and PUT the
     * bytes where LinkedIn says. Returns the image URN the post carries.
     *
     * @throws RequestFailed
     */
    public function upload(string $accessToken, string $ownerUrn, string $imageUrl): string
    {
        $initialized = $this->send(fn () => $this->request($accessToken)
            ->acceptJson()
            ->post(self::INITIALIZE, ['initializeUploadRequest' => ['owner' => $ownerUrn]]));

        $uploadUrl = $initialized->json('value.uploadUrl');
        $urn = $initialized->json('value.image');

        if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($urn) || $urn === '') {
            throw new RequestFailed('LinkedIn returned no upload URL for the image.', $initialized->status());
        }

        [$binary, $type] = MediaDownload::fetch($imageUrl, 'LinkedIn');

        $this->send(fn () => $this->request($accessToken)
            ->timeout((int) config('social-autopost.http.upload_timeout', 120))
            ->withBody($binary, $type)
            ->put($uploadUrl));

        return $urn;
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->timeout((int) config('social-autopost.http.timeout', 20))
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => (string) config('social-autopost.networks.linkedin.api_version', '202609'),
            ]);
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws RequestFailed
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach LinkedIn: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw Failure::fromResponse($response);
        }

        return $response;
    }
}
