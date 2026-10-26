<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;

/** The only class that publishes to X: POST /2/tweets. */
final class PostsClient
{
    private const URL = 'https://api.x.com/2/tweets';

    /**
     * @return string|null The post id; null when X accepted the post without returning its id.
     *
     * @throws RequestFailed
     */
    public function create(string $accessToken, string $text, ?string $mediaId = null): ?string
    {
        $payload = ['text' => $text];

        if ($mediaId !== null) {
            $payload['media'] = ['media_ids' => [$mediaId]];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('social-autopost.http.timeout', 20))
                ->acceptJson()
                ->post(self::URL, $payload);
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach X: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw XFailure::from($response);
        }

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        $id = $response->json('data.id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
