<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Mastodon;

use Carbon\CarbonImmutable;

/**
 * The stored Mastodon connection, token included. Read and written only by
 * MastodonNetwork through the ConnectionStore; never returned to a response,
 * log or event. Mastodon tokens do not expire, so nothing refreshes.
 */
final readonly class MastodonAccount
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public string $accessToken,
        public ?string $username = null,
        public ?string $name = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'username' => $this->username,
            'name' => $this->name,
            'picture' => $this->picture,
            'scopes' => $this->scopes,
            'connected_at' => $this->connectedAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toArray() returned. Null when the stored data has no access token. */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $accessToken = $string('access_token');

        if ($accessToken === null) {
            return null;
        }

        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];
        $connectedAt = $string('connected_at');

        return new self(
            accessToken: $accessToken,
            username: $string('username'),
            name: $string('name'),
            picture: $string('picture'),
            scopes: array_values(array_filter($scopes, 'is_string')),
            connectedAt: $connectedAt !== null ? CarbonImmutable::parse($connectedAt) : null,
        );
    }
}
