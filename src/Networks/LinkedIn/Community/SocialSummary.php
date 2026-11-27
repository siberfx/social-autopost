<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn\Community;

/** Reaction and comment counts of a post, from LinkedIn's socialMetadata. */
final readonly class SocialSummary
{
    /** @param  array<string, int>  $reactions  Count per reaction type, e.g. ['LIKE' => 12, 'PRAISE' => 3]. */
    public function __construct(
        public array $reactions,
        public int $comments,
        public int $topLevelComments,
        public bool $commentsOpen,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromApi(array $data): self
    {
        $reactions = [];

        foreach ((array) ($data['reactionSummaries'] ?? []) as $type => $summary) {
            if (is_array($summary)) {
                $key = is_string($summary['reactionType'] ?? null) ? $summary['reactionType'] : (string) $type;
                $reactions[$key] = (int) ($summary['count'] ?? 0);
            }
        }

        $comments = is_array($data['commentSummary'] ?? null) ? $data['commentSummary'] : [];

        return new self(
            reactions: $reactions,
            comments: (int) ($comments['count'] ?? 0),
            topLevelComments: (int) ($comments['topLevelCount'] ?? $comments['count'] ?? 0),
            commentsOpen: ($data['commentsState'] ?? 'OPEN') !== 'CLOSED',
        );
    }

    public function totalReactions(): int
    {
        return array_sum($this->reactions);
    }

    /** @return array{reactions: array<string, int>, total_reactions: int, comments: int, top_level_comments: int, comments_open: bool} */
    public function toArray(): array
    {
        return [
            'reactions' => $this->reactions,
            'total_reactions' => $this->totalReactions(),
            'comments' => $this->comments,
            'top_level_comments' => $this->topLevelComments,
            'comments_open' => $this->commentsOpen,
        ];
    }
}
