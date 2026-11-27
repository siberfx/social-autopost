<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn\Community;

use Carbon\CarbonImmutable;

/** A comment on a post, or a reply to a comment. */
final readonly class Comment
{
    /** @param  array<string, mixed>  $raw  LinkedIn's comment, as returned. */
    public function __construct(
        public string $id,
        public ?string $urn,
        public ?string $actor,
        public string $text,
        public ?string $object,
        public ?string $parent,
        public ?CarbonImmutable $createdAt,
        public array $raw = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromApi(array $data): self
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];
        $urn = self::string($data['commentUrn'] ?? null) ?? self::string($data['$URN'] ?? null);
        $id = $data['id'] ?? null;
        $time = $created['time'] ?? null;

        return new self(
            id: is_scalar($id) && (string) $id !== '' ? (string) $id : self::idFromUrn($urn),
            urn: $urn,
            actor: self::string($data['actor'] ?? null) ?? self::string($created['actor'] ?? null),
            text: is_string($message['text'] ?? null) ? $message['text'] : '',
            object: self::string($data['object'] ?? null),
            parent: self::string($data['parentComment'] ?? null),
            createdAt: is_numeric($time) ? CarbonImmutable::createFromTimestampMs((int) $time) : null,
            raw: $data,
        );
    }

    /** @return array{id: string, urn: ?string, actor: ?string, text: string, object: ?string, parent: ?string, created_at: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'urn' => $this->urn,
            'actor' => $this->actor,
            'text' => $this->text,
            'object' => $this->object,
            'parent' => $this->parent,
            'created_at' => $this->createdAt?->toIso8601String(),
        ];
    }

    /** urn:li:comment:(urn:li:activity:1,7890) gives 7890. */
    private static function idFromUrn(?string $urn): string
    {
        return $urn !== null && preg_match('/,(\d+)\)$/', $urn, $match) === 1 ? $match[1] : '';
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
