<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Facebook;

use Carbon\CarbonImmutable;

/**
 * The stored Facebook connection, tokens included. Read and written only by
 * FacebookNetwork through the ConnectionStore; never returned to a response, log or event.
 *
 * $accessToken is the Page token used to post. $userAccessToken is the
 * long-lived user token, kept only so disconnect() can remove the app's permissions.
 */
final readonly class FacebookConnection
{
    public function __construct(
        public string $accessToken,
        public string $pageId,
        public ?string $name,
        public ?string $userAccessToken,
        public CarbonImmutable $connectedAt,
    ) {}

    /** @param  array<string, mixed>  $data  What toArray() returned. Null when the stored data is not a Facebook connection. */
    public static function fromArray(array $data): ?self
    {
        $accessToken = $data['access_token'] ?? null;
        $pageId = $data['page_id'] ?? null;
        $connectedAt = $data['connected_at'] ?? null;

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($pageId) || $pageId === '') {
            return null;
        }

        return new self(
            accessToken: $accessToken,
            pageId: $pageId,
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
            userAccessToken: is_string($data['user_access_token'] ?? null) ? $data['user_access_token'] : null,
            connectedAt: is_string($connectedAt) ? CarbonImmutable::parse($connectedAt) : CarbonImmutable::now(),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'page_id' => $this->pageId,
            'name' => $this->name,
            'user_access_token' => $this->userAccessToken,
            'connected_at' => $this->connectedAt->toIso8601String(),
        ];
    }
}
