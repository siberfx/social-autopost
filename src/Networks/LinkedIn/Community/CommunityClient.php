<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn\Community;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\LinkedIn\Failure;

/**
 * Raw calls to LinkedIn's Community Management API (versioned /rest/ endpoints):
 * posts, comments, reactions, social metadata and organization statistics.
 * Knows nothing about the stored connection; Community does.
 */
final class CommunityClient
{
    private const BASE = 'https://api.linkedin.com/rest/';

    /**
     * @return list<array<string, mixed>> The author's posts, newest first.
     *
     * @throws RequestFailed
     */
    public function posts(string $token, string $author, int $count = 10, int $start = 0): array
    {
        $response = $this->send(fn () => $this->rest($token)
            ->withHeaders(['X-RestLi-Method' => 'FINDER'])
            ->get(self::BASE.'posts?'.self::query([
                'q' => 'author',
                'author' => rawurlencode($author),
                'count' => $count,
                'start' => $start,
                'sortBy' => 'LAST_MODIFIED',
            ])));

        return self::elements($response);
    }

    /**
     * @return array<string, mixed>|null Null when LinkedIn does not know the post.
     *
     * @throws RequestFailed
     */
    public function post(string $token, string $postUrn): ?array
    {
        $response = $this->send(fn () => $this->rest($token)->get(self::BASE.'posts/'.rawurlencode($postUrn)), allowNotFound: true);

        $json = $response?->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Deleting a post that is already gone succeeds.
     *
     * @throws RequestFailed
     */
    public function deletePost(string $token, string $postUrn): void
    {
        $this->send(fn () => $this->rest($token)
            ->withHeaders(['X-RestLi-Method' => 'DELETE'])
            ->delete(self::BASE.'posts/'.rawurlencode($postUrn)), allowNotFound: true);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestFailed
     */
    public function comments(string $token, string $entityUrn, int $count = 10, int $start = 0): array
    {
        $response = $this->send(fn () => $this->rest($token)
            ->get(self::BASE.'socialActions/'.rawurlencode($entityUrn).'/comments?'.self::query([
                'count' => $count,
                'start' => $start,
            ])));

        return self::elements($response);
    }

    /**
     * @return array<string, mixed> The created comment.
     *
     * @throws RequestFailed
     */
    public function comment(string $token, string $actor, string $entityUrn, string $text, ?string $parentComment = null): array
    {
        $body = array_filter([
            'actor' => $actor,
            'object' => $entityUrn,
            'message' => ['text' => $text],
            'parentComment' => $parentComment,
        ], fn ($value) => $value !== null);

        $response = $this->send(fn () => $this->rest($token)
            ->post(self::BASE.'socialActions/'.rawurlencode($parentComment ?? $entityUrn).'/comments', $body));

        $json = $response?->json();
        $comment = is_array($json) ? $json : [];

        // Some versions answer 201 with only the id in a header.
        $id = $response?->header('x-restli-id');

        if (! isset($comment['id']) && is_string($id) && $id !== '') {
            $comment['id'] = $id;
        }

        return $comment + ['actor' => $actor, 'object' => $entityUrn, 'message' => ['text' => $text]];
    }

    /**
     * Deleting a comment that is already gone succeeds.
     *
     * @throws RequestFailed
     */
    public function deleteComment(string $token, string $actor, string $entityUrn, string $commentId): void
    {
        $this->send(fn () => $this->rest($token)
            ->delete(self::BASE.'socialActions/'.rawurlencode($entityUrn).'/comments/'.rawurlencode($commentId).'?'.self::query([
                'actor' => rawurlencode($actor),
            ])), allowNotFound: true);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestFailed
     */
    public function reactions(string $token, string $entityUrn, int $count = 10, int $start = 0): array
    {
        $response = $this->send(fn () => $this->rest($token)
            ->withHeaders(['X-RestLi-Method' => 'FINDER'])
            ->get(self::BASE.'reactions/(entity:'.rawurlencode($entityUrn).')?'.self::query([
                'q' => 'entity',
                'sort' => '(value:REVERSE_CHRONOLOGICAL)',
                'count' => $count,
                'start' => $start,
            ])));

        return self::elements($response);
    }

    /** @throws RequestFailed */
    public function react(string $token, string $actor, string $entityUrn, string $type): void
    {
        $this->send(fn () => $this->rest($token)
            ->post(self::BASE.'reactions?'.self::query(['actor' => rawurlencode($actor)]), [
                'root' => $entityUrn,
                'reactionType' => $type,
            ]));
    }

    /**
     * Removing a reaction that is already gone succeeds.
     *
     * @throws RequestFailed
     */
    public function unreact(string $token, string $actor, string $entityUrn): void
    {
        $this->send(fn () => $this->rest($token)
            ->delete(self::BASE.'reactions/(actor:'.rawurlencode($actor).',entity:'.rawurlencode($entityUrn).')'), allowNotFound: true);
    }

    /**
     * @return array<string, mixed> reactionSummaries, commentSummary, commentsState.
     *
     * @throws RequestFailed
     */
    public function socialMetadata(string $token, string $entityUrn): array
    {
        $json = $this->send(fn () => $this->rest($token)->get(self::BASE.'socialMetadata/'.rawurlencode($entityUrn)))?->json();

        return is_array($json) ? $json : [];
    }

    /** @throws RequestFailed */
    public function followerCount(string $token, string $organizationUrn): int
    {
        $response = $this->send(fn () => $this->rest($token)
            ->get(self::BASE.'networkSizes/'.rawurlencode($organizationUrn).'?'.self::query([
                'edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER',
            ])));

        $size = $response?->json('firstDegreeSize');

        return is_numeric($size) ? (int) $size : 0;
    }

    /**
     * Lifetime totals without a range; one element per day with one.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestFailed
     */
    public function shareStatistics(string $token, string $organizationUrn, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = [
            'q' => 'organizationalEntity',
            'organizationalEntity' => rawurlencode($organizationUrn),
        ];

        if ($from !== null) {
            $end = ($to ?? now())->getTimestampMs();
            $query['timeIntervals'] = "(timeRange:(start:{$from->getTimestampMs()},end:{$end}),timeGranularityType:DAY)";
        }

        return self::elements($this->send(fn () => $this->rest($token)
            ->get(self::BASE.'organizationalEntityShareStatistics?'.self::query($query))));
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws RequestFailed
     */
    private function send(callable $request, bool $allowNotFound = false): ?Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach LinkedIn: '.$e->getMessage(), 0);
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw Failure::fromResponse($response);
        }

        return $response;
    }

    private function rest(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout((int) config('social-autopost.http.timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => (string) config('social-autopost.networks.linkedin.api_version', '202609'),
            ]);
    }

    /**
     * Rest.li wants URNs encoded but its own (…:…,…) syntax untouched, so values
     * arrive already encoded and are joined as they are.
     *
     * @param  array<string, string|int>  $params
     */
    private static function query(array $params): string
    {
        return implode('&', array_map(fn ($key, $value) => $key.'='.$value, array_keys($params), $params));
    }

    /** @return list<array<string, mixed>> */
    private static function elements(?Response $response): array
    {
        $elements = $response?->json('elements');

        return is_array($elements) ? array_values(array_filter($elements, 'is_array')) : [];
    }
}
