<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\TikTok;

use Carbon\CarbonImmutable;

/**
 * The stored TikTok connection, tokens included. Lives only between the
 * ConnectionStore and TikTokNetwork; it is never returned, logged or cached.
 *
 * The access token lasts a day ($expiresAt); the refresh token a year
 * ($refreshExpiresAt), after which the account must connect again.
 */
final readonly class TikTokAccount
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public string $accessToken,
        public string $openId,
        public ?string $refreshToken = null,
        public ?string $name = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $refreshExpiresAt = null,
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    /** Whether the access token has run out (with a minute's margin) and needs a refresh before use. */
    public function accessExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isBefore(CarbonImmutable::now()->addMinute());
    }

    /**
     * The same account with a new pair of tokens. TikTok may rotate the refresh token.
     *
     * @param  array{access_token: string, refresh_token: ?string, expires_at: ?CarbonImmutable, refresh_expires_at: ?CarbonImmutable}  $tokens
     */
    public function withTokens(array $tokens): self
    {
        return new self(
            accessToken: $tokens['access_token'],
            openId: $this->openId,
            refreshToken: $tokens['refresh_token'] ?? $this->refreshToken,
            name: $this->name,
            picture: $this->picture,
            scopes: $this->scopes,
            expiresAt: $tokens['expires_at'],
            refreshExpiresAt: $tokens['refresh_expires_at'] ?? $this->refreshExpiresAt,
            connectedAt: $this->connectedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'open_id' => $this->openId,
            'refresh_token' => $this->refreshToken,
            'name' => $this->name,
            'picture' => $this->picture,
            'scopes' => $this->scopes,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'refresh_expires_at' => $this->refreshExpiresAt?->toIso8601String(),
            'connected_at' => $this->connectedAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toArray() returned. Null when the stored data is not a TikTok connection. */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $date = fn (string $key): ?CarbonImmutable => ($value = $string($key)) !== null ? CarbonImmutable::parse($value) : null;
        $accessToken = $string('access_token');
        $openId = $string('open_id');

        if ($accessToken === null || $openId === null) {
            return null;
        }

        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        return new self(
            accessToken: $accessToken,
            openId: $openId,
            refreshToken: $string('refresh_token'),
            name: $string('name'),
            picture: $string('picture'),
            scopes: array_values(array_filter($scopes, 'is_string')),
            expiresAt: $date('expires_at'),
            refreshExpiresAt: $date('refresh_expires_at'),
            connectedAt: $date('connected_at'),
        );
    }
}
