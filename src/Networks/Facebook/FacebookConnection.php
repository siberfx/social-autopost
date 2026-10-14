<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Facebook;

use Carbon\CarbonImmutable;
use Siberfx\SocialAutopost\Data\Page;

/**
 * The stored Facebook connection, tokens included. Read and written only by
 * FacebookNetwork through the ConnectionStore; never returned to a response, log or event.
 *
 * $pages holds every Page the account manages, each with its own Page token.
 * $accessToken and $pageId are those of the first selected Page. $userAccessToken
 * is the long-lived user token, kept only so disconnect() can remove the app's permissions.
 */
final readonly class FacebookConnection
{
    public string $accessToken;

    public string $pageId;

    public ?string $name;

    /** @var non-empty-list<string> */
    public array $selectedPages;

    /**
     * @param  non-empty-list<array{id: string, name: ?string, picture: ?string, access_token: string}>  $pages
     * @param  list<string>  $selectedPages  Unknown ids are dropped; none left selects the first Page.
     */
    public function __construct(
        public array $pages,
        array $selectedPages,
        public ?string $userAccessToken,
        public CarbonImmutable $connectedAt,
    ) {
        $ids = array_column($pages, 'id');
        $selected = array_values(array_unique(array_intersect($selectedPages, $ids)));
        $this->selectedPages = $selected !== [] ? $selected : [$ids[0]];

        $first = $this->page($this->selectedPages[0]) ?? $pages[0];
        $this->accessToken = $first['access_token'];
        $this->pageId = $first['id'];
        $this->name = $first['name'];
    }

    /**
     * @param  array<string, mixed>  $data  What toArray() returned, or the single-Page shape stored before 1.2.
     *                                      Null when the stored data is not a Facebook connection.
     */
    public static function fromArray(array $data): ?self
    {
        $connectedAt = $data['connected_at'] ?? null;
        $pages = [];

        foreach (is_array($data['pages'] ?? null) ? $data['pages'] : [] as $page) {
            $page = is_array($page) ? self::pageFrom($page) : null;

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        // Stored before 1.2: one Page, its token at the top level.
        if ($pages === []) {
            $page = self::pageFrom(['id' => $data['page_id'] ?? null, 'name' => $data['name'] ?? null, 'access_token' => $data['access_token'] ?? null]);

            if ($page === null) {
                return null;
            }

            $pages = [$page];
        }

        $selected = is_array($data['selected_pages'] ?? null) ? array_values(array_filter($data['selected_pages'], 'is_string')) : [];

        return new self(
            pages: $pages,
            selectedPages: $selected,
            userAccessToken: is_string($data['user_access_token'] ?? null) ? $data['user_access_token'] : null,
            connectedAt: is_string($connectedAt) ? CarbonImmutable::parse($connectedAt) : CarbonImmutable::now(),
        );
    }

    /**
     * One managed Page as Facebook lists it, or null without an id or token.
     *
     * @param  array<mixed>  $data
     * @return array{id: string, name: ?string, picture: ?string, access_token: string}|null
     */
    public static function pageFrom(array $data): ?array
    {
        $id = $data['id'] ?? null;
        $id = is_int($id) ? (string) $id : $id;
        $token = $data['access_token'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($token) || $token === '') {
            return null;
        }

        $picture = $data['picture'] ?? null;
        $picture = is_array($picture) ? ($picture['data']['url'] ?? null) : $picture;

        return [
            'id' => $id,
            'name' => is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : null,
            'picture' => is_string($picture) && $picture !== '' ? $picture : null,
            'access_token' => $token,
        ];
    }

    /** @return array{id: string, name: ?string, picture: ?string, access_token: string}|null */
    public function page(string $id): ?array
    {
        foreach ($this->pages as $page) {
            if ($page['id'] === $id) {
                return $page;
            }
        }

        return null;
    }

    /** @return list<Page> The Pages without their tokens. */
    public function publicPages(): array
    {
        return array_map(fn (array $page) => new Page($page['id'], Page::TYPE_PAGE, $page['name'], $page['picture']), $this->pages);
    }

    /** @param  list<string>  $ids */
    public function withSelected(array $ids): self
    {
        return new self($this->pages, $ids, $this->userAccessToken, $this->connectedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            // The first selected Page, kept at the top level as before 1.2.
            'access_token' => $this->accessToken,
            'page_id' => $this->pageId,
            'name' => $this->name,
            'pages' => $this->pages,
            'selected_pages' => $this->selectedPages,
            'user_access_token' => $this->userAccessToken,
            'connected_at' => $this->connectedAt->toIso8601String(),
        ];
    }
}
