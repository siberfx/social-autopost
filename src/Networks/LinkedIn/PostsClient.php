<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/** The only class that publishes to LinkedIn: POST /rest/posts. */
final class PostsClient
{
    private const URL = 'https://api.linkedin.com/rest/posts';

    /**
     * Creates an article (link) post, or an image post when $imageUrn names
     * an image ImagesClient uploaded.
     *
     * @return string|null The post URN; null when LinkedIn accepted the post without returning its id.
     *
     * @throws RequestFailed
     */
    public function create(string $accessToken, string $authorUrn, LinkPost $post, ?string $imageUrn = null): ?string
    {
        $content = $imageUrn !== null
            ? ['media' => ['id' => $imageUrn, 'title' => $post->title()]]
            : ['article' => array_filter([
                'source' => $post->url(),
                'title' => $post->title(),
                'description' => $post->descriptionText(),
            ], fn (string $value) => $value !== '')];

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('social-autopost.http.timeout', 20))
                ->acceptJson()
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'LinkedIn-Version' => (string) config('social-autopost.networks.linkedin.api_version', '202609'),
                ])
                ->post(self::URL, [
                    'author' => $authorUrn,
                    'commentary' => LittleText::escape($post->commentaryText()),
                    'visibility' => (string) config('social-autopost.networks.linkedin.visibility', 'PUBLIC'),
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'content' => $content,
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false,
                ]);
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach LinkedIn: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw Failure::fromResponse($response);
        }

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        $urn = $response->header('x-restli-id') ?: $response->json('id');

        return is_string($urn) && $urn !== '' ? $urn : null;
    }
}
