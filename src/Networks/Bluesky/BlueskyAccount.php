<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Bluesky;

use Carbon\CarbonImmutable;

/**
 * The stored Bluesky session, tokens included. Read and written only by
 * BlueskyNetwork through the ConnectionStore; never returned to a response,
 * log or event. The access token lasts hours and is refreshed; a failed
 * refresh signs in again with the configured app password.
 */
final readonly class BlueskyAccount
{
    public function __construct(
        public string $accessJwt,
        public string $refreshJwt,
        public string $did,
        public ?string $handle = null,
        public ?string $name = null,
        public ?string $picture = null,
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    /** The same session with a new pair of tokens. */
    public function withTokens(string $accessJwt, string $refreshJwt): self
    {
        return new self(
            accessJwt: $accessJwt,
            refreshJwt: $refreshJwt,
            did: $this->did,
            handle: $this->handle,
            name: $this->name,
            picture: $this->picture,
            connectedAt: $this->connectedAt,
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'access_jwt' => $this->accessJwt,
            'refresh_jwt' => $this->refreshJwt,
            'did' => $this->did,
            'handle' => $this->handle,
            'name' => $this->name,
            'picture' => $this->picture,
            'connected_at' => $this->connectedAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toArray() returned. Null when the stored data is not a Bluesky session. */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $accessJwt = $string('access_jwt');
        $refreshJwt = $string('refresh_jwt');
        $did = $string('did');

        if ($accessJwt === null || $refreshJwt === null || $did === null) {
            return null;
        }

        $connectedAt = $string('connected_at');

        return new self(
            accessJwt: $accessJwt,
            refreshJwt: $refreshJwt,
            did: $did,
            handle: $string('handle'),
            name: $string('name'),
            picture: $string('picture'),
            connectedAt: $connectedAt !== null ? CarbonImmutable::parse($connectedAt) : null,
        );
    }
}
