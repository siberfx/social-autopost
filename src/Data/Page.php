<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Data;

/**
 * A place a network can post to: the member's own profile, a LinkedIn
 * organization page or a Facebook Page. Never carries a token.
 */
final readonly class Page
{
    public const TYPE_MEMBER = 'member';

    public const TYPE_ORGANIZATION = 'organization';

    public const TYPE_PAGE = 'page';

    /** @param  string  $id  A LinkedIn URN (urn:li:person:…, urn:li:organization:…) or a Facebook Page id. */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $name = null,
        public ?string $picture = null,
    ) {}

    /** @param  array<mixed>  $data  What toArray() returned. Null when there is no id. */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $id = $string('id');

        if ($id === null) {
            return null;
        }

        return new self($id, $string('type') ?? self::TYPE_PAGE, $string('name'), $string('picture'));
    }

    /**
     * @param  array<mixed>  $items
     * @return list<self>
     */
    public static function listFrom(array $items): array
    {
        $pages = [];

        foreach ($items as $item) {
            $page = is_array($item) ? self::fromArray($item) : null;

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /** @return array{id: string, type: string, name: ?string, picture: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'picture' => $this->picture,
        ];
    }
}
