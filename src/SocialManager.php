<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\HasPages;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Contracts\SocialNetwork;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Events\Connected;
use Siberfx\SocialAutopost\Events\Disconnected;
use Siberfx\SocialAutopost\Events\Shared;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\NotShareable;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Exceptions\UnknownNetwork;
use Siberfx\SocialAutopost\Jobs\PublishToSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Siberfx\SocialAutopost\Support\Columns;
use Throwable;

/**
 * Everything the app calls: which networks are on, connecting, choosing
 * pages, sharing and queueing. A network that is switched off is invisible
 * to all of it.
 */
final class SocialManager
{
    /** @var array<string, SocialNetwork> */
    private array $networks = [];

    /** @return list<string> Every network listed in the config, on or off. */
    public function keys(): array
    {
        $networks = config('social-autopost.networks', []);

        return array_keys(is_array($networks) ? array_filter($networks, 'is_array') : []);
    }

    /**
     * The network's driver. Works for a switched-off network too; use isEnabled() to decide what to show.
     *
     * @throws UnknownNetwork
     */
    public function network(string $key): SocialNetwork
    {
        if (isset($this->networks[$key])) {
            return $this->networks[$key];
        }

        $config = config("social-autopost.networks.{$key}");

        if (! is_array($config)) {
            throw new UnknownNetwork($key);
        }

        $class = $config['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, SocialNetwork::class)) {
            throw new InvalidArgumentException("The [{$key}] network needs a class implementing ".SocialNetwork::class.'.');
        }

        return $this->networks[$key] = app()->make($class);
    }

    /** @return Collection<int, string> The keys of the networks switched on. */
    public function enabled(): Collection
    {
        return collect($this->keys())->filter(fn (string $key) => $this->isEnabled($key))->values();
    }

    public function isEnabled(string $key): bool
    {
        return in_array($key, $this->keys(), true) && (bool) config("social-autopost.networks.{$key}.enabled", false);
    }

    /** Switched on and set to post automatically when a model goes live. */
    public function autopostEnabled(string $key): bool
    {
        return $this->isEnabled($key) && (bool) config("social-autopost.networks.{$key}.autopost", false);
    }

    /** Whether a connection is stored. Never calls the network. */
    public function isConnected(string $key): bool
    {
        return $this->network($key)->isConnected();
    }

    /** @throws NetworkDisabled|UnknownNetwork */
    public function connection(string $key): Connection
    {
        $this->requireEnabled($key);

        return $this->network($key)->connection();
    }

    /**
     * The URL to send the browser to for the network's consent screen.
     *
     * @throws NetworkDisabled|UnknownNetwork
     */
    public function connect(string $key): string
    {
        $this->requireEnabled($key);

        return $this->network($key)->beginConnection();
    }

    /**
     * Finishes the OAuth flow and fires Connected.
     *
     * @throws ConnectionFailed
     */
    public function completeConnection(string $key, Request $request): Connection
    {
        $this->requireEnabled($key);

        $connection = $this->network($key)->completeConnection($request);
        event(new Connected($key, $connection));

        return $connection;
    }

    /** Revokes at the network and forgets the connection. Fires Disconnected. Returns whether the network confirmed the revocation. */
    public function disconnect(string $key): bool
    {
        $revoked = $this->network($key)->disconnect();
        event(new Disconnected($key, $revoked));

        return $revoked;
    }

    /** Whether the network can post to more than one page (LinkedIn, Facebook). */
    public function hasPages(string $key): bool
    {
        return $this->network($key) instanceof HasPages;
    }

    /**
     * Every page the connected account can post to; empty when not connected.
     *
     * @return list<Page>
     *
     * @throws NetworkDisabled|UnknownNetwork
     * @throws InvalidArgumentException When the network has no pages.
     */
    public function pages(string $key): array
    {
        $this->requireEnabled($key);

        return $this->pagesOf($key)->pages();
    }

    /**
     * The ids of the pages shares go to.
     *
     * @return list<string>
     *
     * @throws NetworkDisabled|UnknownNetwork
     * @throws InvalidArgumentException When the network has no pages.
     */
    public function selectedPages(string $key): array
    {
        $this->requireEnabled($key);

        return $this->pagesOf($key)->selectedPages();
    }

    /**
     * Choose the pages shares go to: one or more ids from pages().
     *
     * @param  array<int, string>  $ids
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork
     * @throws InvalidArgumentException When the network has no pages, $ids is empty or names an unknown page.
     */
    public function selectPages(string $key, array $ids): Connection
    {
        $this->requireEnabled($key);

        return $this->pagesOf($key)->selectPages(array_values(array_map('strval', $ids)));
    }

    /**
     * Post the model now, to one network or to every enabled and connected one.
     * A network with pages posts to every selected page.
     *
     * Named network: its failure is recorded and rethrown (with pages: when every
     * page failed). All networks: each failure is recorded and the others still
     * run, so one outage never stops the rest. Re-sharing an already posted model is allowed.
     *
     * @return Collection<int, SharedPost> One record per network (and page) tried: posted or failed.
     *
     * @throws NotShareable|NetworkDisabled|NotConnected|UnknownNetwork|RequestFailed|InvalidArgumentException
     */
    public function share(Model&ShareableOnSocial $model, ?string $network = null, string $trigger = SharedPost::TRIGGER_MANUAL): Collection
    {
        if (! $model->isLiveForSocial()) {
            throw new NotShareable;
        }

        if ($network !== null) {
            $this->assertReady($network);

            return $this->attempt($model, $network, $trigger, rethrow: true);
        }

        // Built once: an invalid post is the model's problem, not each network's.
        $post = $model->toSocialPost();

        return $this->targets()
            ->flatMap(fn (string $key) => $this->attempt($model, $key, $trigger, rethrow: false, post: $post))
            ->values();
    }

    /**
     * Post to one network and record it. Throws on failure without recording a
     * failure row: the caller decides, as a queued job retries first.
     *
     * A network with pages posts to every selected page; a failure is thrown
     * once every page was tried. The first posted record is returned.
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork|RequestFailed|InvalidArgumentException
     */
    public function shareTo(Model&ShareableOnSocial $model, string $network, string $trigger = SharedPost::TRIGGER_MANUAL, ?LinkPost $post = null): SharedPost
    {
        $this->assertReady($network);
        $driver = $this->network($network);
        $post ??= $model->toSocialPost();

        if (! $driver instanceof HasPages) {
            return $this->record($model, $network, null, $driver->publish($model, $post), $trigger);
        }

        $first = null;
        $failure = null;

        foreach ($driver->selectedPages() as $page) {
            try {
                $first ??= $this->shareToPage($model, $network, $page, $trigger, $post);
            } catch (RequestFailed $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $first ?? throw new NotConnected($driver->label().' has no page selected.');
    }

    /**
     * Post to one page of a network with pages and record it. Throws on failure
     * without recording a failure row.
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork|RequestFailed
     * @throws InvalidArgumentException When the network has no pages or $page is not one of them.
     */
    public function shareToPage(Model&ShareableOnSocial $model, string $network, string $page, string $trigger = SharedPost::TRIGGER_MANUAL, ?LinkPost $post = null): SharedPost
    {
        $this->assertReady($network);

        $id = $this->pagesOf($network)->publishToPage($model, $post ?? $model->toSocialPost(), $page);

        return $this->record($model, $network, $page, $id, $trigger);
    }

    /**
     * Post to some pages of a network: the given ones, or the selected ones when
     * $pages is null. Each page is tried; a failure is recorded and the rest
     * still run. Throws the last failure only when every page failed.
     *
     * @param  array<int, string>|null  $pages
     * @return Collection<int, SharedPost> One record per page: posted or failed.
     *
     * @throws NotShareable|NetworkDisabled|NotConnected|UnknownNetwork|RequestFailed
     * @throws InvalidArgumentException When the network has no pages, or $pages is empty or names an unknown page.
     */
    public function shareToPages(Model&ShareableOnSocial $model, string $network, ?array $pages = null, string $trigger = SharedPost::TRIGGER_MANUAL): Collection
    {
        if (! $model->isLiveForSocial()) {
            throw new NotShareable;
        }

        $this->assertReady($network);
        $driver = $this->pagesOf($network);

        if ($pages !== null) {
            $known = array_map(fn (Page $page) => $page->id, $driver->pages());
            $pages = array_values(array_unique(array_map('strval', $pages)));

            if ($pages === []) {
                throw new InvalidArgumentException('Choose at least one page.');
            }

            foreach ($pages as $page) {
                if (! in_array($page, $known, true)) {
                    throw new InvalidArgumentException("[{$page}] is not a page this {$this->network($network)->label()} account can post to.");
                }
            }
        }

        return $this->attemptPages($model, $network, $pages ?? $driver->selectedPages(), $trigger, rethrow: true, post: $model->toSocialPost());
    }

    /**
     * Queue a share in the background: to one network, or to every enabled and connected one.
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork
     */
    public function queue(Model&ShareableOnSocial $model, ?string $network = null, string $trigger = SharedPost::TRIGGER_MANUAL): void
    {
        $this->dispatchJobs($model, $network, $trigger, null);
    }

    /**
     * Queue a share for a moment in the future: to one network, or to every
     * enabled and connected one. The job checks again when the time comes, so
     * a model no longer live, a network switched off or disconnected since,
     * or a model that already posted there post nothing.
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork
     */
    public function shareAt(Model&ShareableOnSocial $model, DateTimeInterface $when, ?string $network = null): void
    {
        $this->dispatchJobs($model, $network, SharedPost::TRIGGER_SCHEDULED, $when);
    }

    /** One job per network, now or delayed until $at. */
    private function dispatchJobs(Model&ShareableOnSocial $model, ?string $network, string $trigger, ?DateTimeInterface $at): void
    {
        $networks = $network !== null ? [$network] : $this->targets()->all();

        foreach ($networks as $key) {
            if ($network !== null) {
                $this->assertReady($key);
            }

            $job = (new PublishToSocial($model->getMorphClass(), $model->getKey(), $key, $trigger))
                ->onConnection(config('social-autopost.autopost.queue_connection'))
                ->onQueue(config('social-autopost.autopost.queue'));

            dispatch($at === null ? $job : $job->delay($at));
        }
    }

    /**
     * Whether the model was posted: to any network, to one, or to one page of
     * one. A page is ignored until the target column is migrated.
     */
    public function wasPosted(Model $model, ?string $network = null, ?string $page = null): bool
    {
        return SharedPost::query()
            ->for($model)
            ->posted()
            ->when($network !== null, fn ($query) => $query->forNetwork((string) $network))
            ->when($page !== null && self::tracksPages(), fn ($query) => $query->forTarget((string) $page))
            ->exists();
    }

    /** Whether shares record the page they went to (the target column is migrated). */
    public static function tracksPages(): bool
    {
        return Columns::has((new SharedPost)->getTable(), 'target');
    }

    /**
     * Enabled and connected: the networks "all" actions use.
     *
     * @return Collection<int, string>
     */
    public function targets(): Collection
    {
        return $this->enabled()->filter(fn (string $key) => $this->isConnected($key))->values();
    }

    /**
     * Records one network's attempt: every selected page for a network with
     * pages. A failure is stored as a failed row, then either rethrown or returned.
     *
     * @return Collection<int, SharedPost>
     *
     * @throws RequestFailed|NetworkDisabled|NotConnected|UnknownNetwork
     */
    private function attempt(Model&ShareableOnSocial $model, string $network, string $trigger, bool $rethrow, ?LinkPost $post = null): Collection
    {
        $driver = $this->network($network);

        if ($driver instanceof HasPages) {
            return $this->attemptPages($model, $network, $driver->selectedPages(), $trigger, $rethrow, $post);
        }

        try {
            return collect([$this->shareTo($model, $network, $trigger, $post)]);
        } catch (RequestFailed $e) {
            $record = $this->recordFailure($model, $network, null, $trigger, $e);

            if ($rethrow) {
                throw $e;
            }

            return collect([$record]);
        }
    }

    /**
     * One record per page. With $rethrow, the last failure is thrown when every page failed.
     *
     * @param  list<string>  $pages
     * @return Collection<int, SharedPost>
     *
     * @throws RequestFailed
     */
    private function attemptPages(Model&ShareableOnSocial $model, string $network, array $pages, string $trigger, bool $rethrow, ?LinkPost $post = null): Collection
    {
        $post ??= $model->toSocialPost();
        $records = collect();
        $failure = null;

        foreach ($pages as $page) {
            try {
                $records->push($this->shareToPage($model, $network, $page, $trigger, $post));
            } catch (RequestFailed $e) {
                $failure = $e;
                $records->push($this->recordFailure($model, $network, $page, $trigger, $e));
            }
        }

        if ($rethrow && $failure !== null && $records->every(fn (SharedPost $record) => $record->status === SharedPost::STATUS_FAILED)) {
            throw $failure;
        }

        return $records;
    }

    private function record(Model&ShareableOnSocial $model, string $network, ?string $page, ?string $postId, string $trigger): SharedPost
    {
        $record = SharedPost::query()->create($this->withTarget([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'network' => $network,
            'post_id' => $postId,
            'status' => SharedPost::STATUS_POSTED,
            'trigger' => $trigger,
            'posted_at' => now(),
        ], $page));

        event(new Shared($network, $model, $record));

        return $record;
    }

    private function recordFailure(Model $model, string $network, ?string $page, string $trigger, Throwable $e): SharedPost
    {
        return SharedPost::query()->create($this->withTarget([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'network' => $network,
            'status' => SharedPost::STATUS_FAILED,
            'trigger' => $trigger,
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'posted_at' => null,
        ], $page));
    }

    /**
     * Adds the page when there is one and the column exists, so apps that have
     * not run the 1.2 migration keep working.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withTarget(array $attributes, ?string $page): array
    {
        return $page !== null && self::tracksPages() ? $attributes + ['target' => $page] : $attributes;
    }

    /** @throws InvalidArgumentException|UnknownNetwork */
    private function pagesOf(string $key): HasPages
    {
        $network = $this->network($key);

        if (! $network instanceof HasPages) {
            throw new InvalidArgumentException("The [{$key}] network posts to one account and has no pages to choose.");
        }

        return $network;
    }

    /** @throws NetworkDisabled|NotConnected|UnknownNetwork */
    private function assertReady(string $key): void
    {
        $this->requireEnabled($key);

        if (! $this->isConnected($key)) {
            throw new NotConnected($this->network($key)->label().' is not connected.');
        }
    }

    /** @throws NetworkDisabled|UnknownNetwork */
    private function requireEnabled(string $key): void
    {
        $this->network($key);

        if (! $this->isEnabled($key)) {
            throw new NetworkDisabled($key);
        }
    }
}
