<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn\Community;

/** Follower count and share statistics of a LinkedIn Page: lifetime, or summed over a date range. */
final readonly class PageStatistics
{
    private const COUNTS = ['impressionCount', 'uniqueImpressionsCount', 'clickCount', 'likeCount', 'commentCount', 'shareCount'];

    public function __construct(
        public string $organization,
        public int $followers,
        public int $impressions,
        public int $uniqueImpressions,
        public int $clicks,
        public int $likes,
        public int $comments,
        public int $shares,
        public float $engagement,
    ) {}

    /** @param  list<array<string, mixed>>  $elements  organizationalEntityShareStatistics elements. */
    public static function fromApi(string $organization, int $followers, array $elements): self
    {
        $totals = array_fill_keys(self::COUNTS, 0);

        foreach ($elements as $element) {
            $stats = is_array($element['totalShareStatistics'] ?? null) ? $element['totalShareStatistics'] : [];

            foreach (self::COUNTS as $key) {
                $totals[$key] += (int) ($stats[$key] ?? 0);
            }
        }

        $interactions = $totals['clickCount'] + $totals['likeCount'] + $totals['commentCount'] + $totals['shareCount'];

        return new self(
            organization: $organization,
            followers: $followers,
            impressions: $totals['impressionCount'],
            uniqueImpressions: $totals['uniqueImpressionsCount'],
            clicks: $totals['clickCount'],
            likes: $totals['likeCount'],
            comments: $totals['commentCount'],
            shares: $totals['shareCount'],
            // LinkedIn's formula: (clicks + likes + comments + shares) / impressions.
            engagement: $totals['impressionCount'] > 0 ? round($interactions / $totals['impressionCount'], 4) : 0.0,
        );
    }

    /** @return array{organization: string, followers: int, impressions: int, unique_impressions: int, clicks: int, likes: int, comments: int, shares: int, engagement: float} */
    public function toArray(): array
    {
        return [
            'organization' => $this->organization,
            'followers' => $this->followers,
            'impressions' => $this->impressions,
            'unique_impressions' => $this->uniqueImpressions,
            'clicks' => $this->clicks,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'engagement' => $this->engagement,
        ];
    }
}
