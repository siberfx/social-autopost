<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Data;

use Carbon\CarbonImmutable;

/** A network's connection as shown to people and APIs. Never carries a token. */
final readonly class Connection
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public string $network,
        public bool $connected,
        public ?string $status = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $connectedAt = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function disconnected(string $network): self
    {
        return new self(network: $network, connected: false);
    }

    /** Whole days until the token expires (negative once expired), or null when it never expires or is unknown. */
    public function daysLeft(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) floor((float) CarbonImmutable::now()->diffInSeconds($this->expiresAt, false) / 86400);
    }

    /**
     * Plain data for the cache. Objects are never cached: Laravel 13 stores
     * refuse to unserialize classes by default (cache.serializable_classes).
     *
     * @return array{network: string, connected: bool, status: ?string, name: ?string, email: ?string, picture: ?string, scopes: list<string>, connected_at: ?string, expires_at: ?string}
     */
    public function toCache(): array
    {
        return [
            'network' => $this->network,
            'connected' => $this->connected,
            'status' => $this->status,
            'name' => $this->name,
            'email' => $this->email,
            'picture' => $this->picture,
            'scopes' => $this->scopes,
            'connected_at' => $this->connectedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toCache() returned. */
    public static function fromCache(array $data): self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $date = fn (string $key): ?CarbonImmutable => ($value = $string($key)) !== null ? CarbonImmutable::parse($value) : null;
        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        return new self(
            network: (string) $string('network'),
            connected: ($data['connected'] ?? false) === true,
            status: $string('status'),
            name: $string('name'),
            email: $string('email'),
            picture: $string('picture'),
            scopes: array_values(array_filter($scopes, 'is_string')),
            connectedAt: $date('connected_at'),
            expiresAt: $date('expires_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toCache() + ['days_left' => $this->daysLeft()];
    }
}
