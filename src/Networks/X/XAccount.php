<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\X;

use Carbon\CarbonImmutable;

/**
 * The stored X connection, tokens included. Lives only between the
 * ConnectionStore and XNetwork; it is never returned, logged or cached.
 */
final readonly class XAccount
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?string $username = null,
        public ?string $name = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    /** The same account with a new pair of tokens. X rotates the refresh token on every refresh. */
    public function withTokens(string $accessToken, ?string $refreshToken): self
    {
        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken ?? $this->refreshToken,
            username: $this->username,
            name: $this->name,
            picture: $this->picture,
            scopes: $this->scopes,
            connectedAt: $this->connectedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
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
            refreshToken: $string('refresh_token'),
            username: $string('username'),
            name: $string('name'),
            picture: $string('picture'),
            scopes: array_values(array_filter($scopes, 'is_string')),
            connectedAt: $connectedAt !== null ? CarbonImmutable::parse($connectedAt) : null,
        );
    }
}
