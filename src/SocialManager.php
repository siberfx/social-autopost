<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Contracts\SocialNetwork;
use Siberfx\SocialAutopost\Data\Connection;
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
use Throwable;

/**
 * Everything the app calls: which networks are on, connecting, sharing and
 * queueing. A network that is switched off is invisible to all of it.
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

    /**
     * Post the model now, to one network or to every enabled and connected one.
     *
     * Named network: its failure is recorded and rethrown. All networks: each
     * failure is recorded and the others still run, so one outage never
     * stops the rest. Re-sharing an already posted model is allowed.
     *
     * @return Collection<int, SharedPost> One record per network tried: posted or failed.
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

            return collect([$this->attempt($model, $network, $trigger, rethrow: true)]);
        }

        // Built once: an invalid post is the model's problem, not each network's.
        $post = $model->toSocialPost();

        return $this->targets()
            ->map(fn (string $key) => $this->attempt($model, $key, $trigger, rethrow: false, post: $post))
            ->values();
    }

    /**
     * Post to one network and record it. Throws on failure without recording a
     * failure row: the caller decides, as a queued job retries first.
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork|RequestFailed|InvalidArgumentException
     */
    public function shareTo(Model&ShareableOnSocial $model, string $network, string $trigger = SharedPost::TRIGGER_MANUAL, ?LinkPost $post = null): SharedPost
    {
        $this->assertReady($network);

        $id = $this->network($network)->publish($model, $post ?? $model->toSocialPost());

        $record = SharedPost::query()->create([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'network' => $network,
            'post_id' => $id,
            'status' => SharedPost::STATUS_POSTED,
            'trigger' => $trigger,
            'posted_at' => now(),
        ]);

        event(new Shared($network, $model, $record));

        return $record;
    }

    /**
     * Queue a share in the background: to one network, or to every enabled and connected one.
     *
     * @throws NetworkDisabled|NotConnected|UnknownNetwork
     */
    public function queue(Model&ShareableOnSocial $model, ?string $network = null, string $trigger = SharedPost::TRIGGER_MANUAL): void
    {
        $networks = $network !== null ? [$network] : $this->targets()->all();

        foreach ($networks as $key) {
            if ($network !== null) {
                $this->assertReady($key);
            }

            dispatch((new PublishToSocial($model->getMorphClass(), $model->getKey(), $key, $trigger))
                ->onConnection(config('social-autopost.autopost.queue_connection'))
                ->onQueue(config('social-autopost.autopost.queue')));
        }
    }

    /** Whether the model was posted, to one network or to any. */
    public function wasPosted(Model $model, ?string $network = null): bool
    {
        return SharedPost::query()
            ->for($model)
            ->posted()
            ->when($network !== null, fn ($query) => $query->forNetwork((string) $network))
            ->exists();
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
     * Records one attempt. A failure is stored as a failed row, then either rethrown or returned.
     *
     * @throws RequestFailed|NetworkDisabled|NotConnected|UnknownNetwork
     */
    private function attempt(Model&ShareableOnSocial $model, string $network, string $trigger, bool $rethrow, ?LinkPost $post = null): SharedPost
    {
        try {
            return $this->shareTo($model, $network, $trigger, $post);
        } catch (RequestFailed $e) {
            $record = $this->recordFailure($model, $network, $trigger, $e);

            if ($rethrow) {
                throw $e;
            }

            return $record;
        }
    }

    private function recordFailure(Model $model, string $network, string $trigger, Throwable $e): SharedPost
    {
        return SharedPost::query()->create([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'network' => $network,
            'status' => SharedPost::STATUS_FAILED,
            'trigger' => $trigger,
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'posted_at' => null,
        ]);
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
