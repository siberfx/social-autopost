<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Instagram;

use Carbon\CarbonImmutable;

/**
 * The stored Instagram connection, token included. Read and written only by
 * InstagramNetwork through the ConnectionStore; never returned to a response, log or event.
 *
 * $accessToken is the long-lived Instagram User token (60 days), refreshed
 * before it runs out. $userId is the professional account posts are published to.
 */
final readonly class InstagramAccount
{
    public function __construct(
        public string $accessToken,
        public string $userId,
        public ?string $username = null,
        public ?string $name = null,
        public ?string $picture = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    /** The same account with a refreshed token. */
    public function withToken(string $accessToken, ?CarbonImmutable $expiresAt): self
    {
        return new self(
            accessToken: $accessToken,
            userId: $this->userId,
            username: $this->username,
            name: $this->name,
            picture: $this->picture,
            expiresAt: $expiresAt,
            connectedAt: $this->connectedAt,
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'user_id' => $this->userId,
            'username' => $this->username,
            'name' => $this->name,
            'picture' => $this->picture,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'connected_at' => $this->connectedAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toArray() returned. Null when the stored data is not an Instagram connection. */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $accessToken = $string('access_token');
        $userId = $string('user_id');

        if ($accessToken === null || $userId === null) {
            return null;
        }

        $expiresAt = $string('expires_at');
        $connectedAt = $string('connected_at');

        return new self(
            accessToken: $accessToken,
            userId: $userId,
            username: $string('username'),
            name: $string('name'),
            picture: $string('picture'),
            expiresAt: $expiresAt !== null ? CarbonImmutable::parse($expiresAt) : null,
            connectedAt: $connectedAt !== null ? CarbonImmutable::parse($connectedAt) : null,
        );
    }
}
