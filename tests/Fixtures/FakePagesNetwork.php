<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Contracts\HasPages;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/** An in-memory network with three pages. Pages listed in $failing answer with that HTTP status. */
class FakePagesNetwork extends FakeNetwork implements HasPages
{
    /** @var array<string, int> Page id => status to fail with. */
    public static array $failing = [];

    /** @var list<string> The pages posted to, in order. */
    public static array $postedTo = [];

    public function key(): string
    {
        return 'paged';
    }

    public function label(): string
    {
        return 'Paged';
    }

    public function connection(): Connection
    {
        return parent::connection()->withPages($this->pages(), $this->selectedPages());
    }

    public function pages(): array
    {
        if (! $this->isConnected()) {
            return [];
        }

        return [
            new Page('me', Page::TYPE_MEMBER, 'Me'),
            new Page('org-1', Page::TYPE_ORGANIZATION, 'Org One'),
            new Page('org-2', Page::TYPE_ORGANIZATION, 'Org Two'),
        ];
    }

    public function selectedPages(): array
    {
        $stored = app(ConnectionStore::class)->get($this->key());

        if ($stored === null) {
            return [];
        }

        $selected = $stored['selected'] ?? null;

        return is_array($selected) && $selected !== [] ? array_values($selected) : ['me'];
    }

    public function selectPages(array $ids): Connection
    {
        $store = app(ConnectionStore::class);
        $stored = $store->get($this->key()) ?? throw new NotConnected;
        $known = array_map(fn (Page $page) => $page->id, $this->pages());

        if ($ids === [] || array_diff($ids, $known) !== []) {
            throw new InvalidArgumentException('Unknown or no page.');
        }

        $store->put($this->key(), ['selected' => $ids] + $stored);

        return $this->connection();
    }

    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        return $this->publishToPage($model, $post, $this->selectedPages()[0]);
    }

    public function publishToPage(Model&ShareableOnSocial $model, LinkPost $post, string $page): ?string
    {
        if (! in_array($page, array_map(fn (Page $known) => $known->id, $this->pages()), true)) {
            throw new InvalidArgumentException('Unknown page.');
        }

        if (isset(self::$failing[$page])) {
            throw new RequestFailed("{$page} failed.", self::$failing[$page]);
        }

        self::$postedTo[] = $page;

        return $page.'-post-'.count(self::$postedTo);
    }
}
