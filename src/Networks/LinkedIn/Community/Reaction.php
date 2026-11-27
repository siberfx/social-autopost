<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn\Community;

use Carbon\CarbonImmutable;

/** One member's or Page's reaction to a post or comment. */
final readonly class Reaction
{
    public const LIKE = 'LIKE';

    /** "Celebrate" */
    public const PRAISE = 'PRAISE';

    /** "Love" */
    public const EMPATHY = 'EMPATHY';

    /** "Insightful" */
    public const INTEREST = 'INTEREST';

    /** "Support" */
    public const APPRECIATION = 'APPRECIATION';

    /** "Funny" */
    public const ENTERTAINMENT = 'ENTERTAINMENT';

    public const TYPES = [self::LIKE, self::PRAISE, self::EMPATHY, self::INTEREST, self::APPRECIATION, self::ENTERTAINMENT];

    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $type,
        public ?string $actor,
        public ?string $root,
        public ?CarbonImmutable $createdAt,
        public array $raw = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromApi(array $data): self
    {
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];
        $time = $created['time'] ?? null;

        return new self(
            type: self::string($data['reactionType'] ?? null) ?? self::LIKE,
            actor: self::string($created['actor'] ?? null) ?? self::string($data['actor'] ?? null),
            root: self::string($data['root'] ?? null),
            createdAt: is_numeric($time) ? CarbonImmutable::createFromTimestampMs((int) $time) : null,
            raw: $data,
        );
    }

    /** @return array{type: string, actor: ?string, root: ?string, created_at: ?string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'actor' => $this->actor,
            'root' => $this->root,
            'created_at' => $this->createdAt?->toIso8601String(),
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
