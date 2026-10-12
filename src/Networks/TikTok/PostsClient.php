<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\TikTok;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * The only class that publishes to TikTok: the Content Posting API with
 * Direct Post. TikTok downloads the media itself (PULL_FROM_URL), so its
 * domain or URL prefix must be verified in the TikTok developer portal.
 */
final class PostsClient
{
    private const CREATOR_INFO = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

    private const VIDEO = 'https://open.tiktokapis.com/v2/post/publish/video/init/';

    private const CONTENT = 'https://open.tiktokapis.com/v2/post/publish/content/init/';

    /** A video caption, and a photo post's description. */
    public const CAPTION_LIMIT = 2200;

    /** A photo post's title. */
    public const PHOTO_TITLE_LIMIT = 90;

    public const PRIVACY_LEVELS = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    /**
     * Post the video, or else the image as a photo post. TikTok first says what
     * this creator may post (privacy levels, comments, duets, stitches), and
     * the post follows those settings.
     *
     * @return string|null TikTok's publish id: the post appears once TikTok has processed the media.
     *
     * @throws RequestFailed
     */
    public function publish(string $accessToken, LinkPost $post, string $privacyLevel): ?string
    {
        $creator = $this->send(fn () => $this->http($accessToken)->post(self::CREATOR_INFO))->json('data');
        $creator = is_array($creator) ? $creator : [];

        $allowed = is_array($creator['privacy_level_options'] ?? null) ? $creator['privacy_level_options'] : [];

        if ($allowed !== [] && ! in_array($privacyLevel, $allowed, true)) {
            throw new RequestFailed("TikTok does not allow {$privacyLevel} posts for this account, only ".implode(', ', $allowed)
                .'. Set TIKTOK_PRIVACY_LEVEL to one of them. Apps TikTok has not audited can only post SELF_ONLY.', 422);
        }

        $caption = mb_substr($post->commentaryText(), 0, self::CAPTION_LIMIT);
        $noComments = ($creator['comment_disabled'] ?? false) === true;

        $response = $post->videoUrl() !== null
            ? $this->send(fn () => $this->http($accessToken)->post(self::VIDEO, [
                'post_info' => [
                    'title' => $caption,
                    'privacy_level' => $privacyLevel,
                    'disable_comment' => $noComments,
                    'disable_duet' => ($creator['duet_disabled'] ?? false) === true,
                    'disable_stitch' => ($creator['stitch_disabled'] ?? false) === true,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $post->videoUrl(),
                ],
            ]))
            : $this->send(fn () => $this->http($accessToken)->post(self::CONTENT, [
                'post_info' => [
                    'title' => mb_substr($post->title(), 0, self::PHOTO_TITLE_LIMIT),
                    'description' => $caption,
                    'privacy_level' => $privacyLevel,
                    'disable_comment' => $noComments,
                    'auto_add_music' => true,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_images' => [$post->imageUrl()],
                    'photo_cover_index' => 0,
                ],
                'post_mode' => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ]));

        // A 2xx means the post was accepted; never fail it for a missing id, or a retry would post twice.
        $id = $response->json('data.publish_id');

        return is_string($id) && $id !== '' ? $id : null;
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
            throw new RequestFailed('Could not reach TikTok: '.$e->getMessage(), 0);
        }

        if (TikTokFailure::failed($response)) {
            throw TikTokFailure::from($response);
        }

        return $response;
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->timeout((int) config('social-autopost.http.timeout', 20))
            ->acceptJson()
            ->asJson();
    }
}
