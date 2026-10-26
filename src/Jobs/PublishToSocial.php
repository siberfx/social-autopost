<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Events\ShareFailed;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Exceptions\UnknownNetwork;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Throwable;

/** Publishes one model to one network. One queued share per model and network at a time. */
final class PublishToSocial implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** The rate limiter that spaces queued shares out per network (networks.{key}.rate_limit). */
    public const LIMITER = 'social-autopost';

    public int $tries;

    /** Seconds the unique lock may outlive a job that never ran (job.unique_for). */
    public int $uniqueFor;

    public function __construct(
        public readonly string $shareableType,
        public readonly int|string $shareableId,
        public readonly string $network,
        public readonly string $trigger = SharedPost::TRIGGER_AUTO,
    ) {
        $this->tries = (int) config('social-autopost.job.tries', 3);
        $this->uniqueFor = (int) config('social-autopost.job.unique_for', 600);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('social-autopost.job.backoff', [60, 300])));
    }

    /** @return list<object> Throttled per network when the network has a rate_limit. */
    public function middleware(): array
    {
        return self::rateLimit($this->network) === null ? [] : [new RateLimited(self::LIMITER)];
    }

    /** With job.retry_for set, time limits the retries instead of `tries`, so a rate limit never burns them. */
    public function retryUntil(): ?CarbonImmutable
    {
        $seconds = config('social-autopost.job.retry_for');

        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }

    /** @return array{posts: int, per_seconds: int}|null The network's queued-share rate limit, when one is configured. */
    public static function rateLimit(string $network): ?array
    {
        $limit = config("social-autopost.networks.{$network}.rate_limit");
        $posts = is_array($limit) && is_numeric($limit['posts'] ?? null) ? (int) $limit['posts'] : 0;
        $seconds = is_array($limit) && is_numeric($limit['per_seconds'] ?? null) ? (int) $limit['per_seconds'] : 0;

        return $posts > 0 && $seconds > 0 ? ['posts' => $posts, 'per_seconds' => $seconds] : null;
    }

    public function handle(SocialManager $social): void
    {
        $model = $this->resolve();

        // Unpublished, deleted, switched off or disconnected since the job was
        // queued: nothing to do.
        if ($model === null || ! $model->isLiveForSocial() || ! $social->isEnabled($this->network)
            || ! $social->isConnected($this->network)) {
            return;
        }

        if ($social->hasPages($this->network) && SocialManager::tracksPages()) {
            $this->shareToPages($social, $model);

            return;
        }

        // Already posted there: nothing to do.
        if ($social->wasPosted($model, $this->network)) {
            return;
        }

        try {
            $social->shareTo($model, $this->network, $this->trigger);
        } catch (RequestFailed $e) {
            // 429, 5xx and network errors are retried; other 4xx never succeed.
            if ($e->isRetryable()) {
                throw $e;
            }

            $this->fail($e);
        } catch (InvalidArgumentException|NotConnected|NetworkDisabled|UnknownNetwork $e) {
            $this->fail($e);
        }
    }

    /**
     * Every selected page not posted to yet, so a retry never posts to a page
     * twice. A retryable failure is thrown after the other pages were tried;
     * otherwise a permanent one fails the job.
     */
    private function shareToPages(SocialManager $social, Model&ShareableOnSocial $model): void
    {
        $retry = null;
        $permanent = null;

        foreach ($social->selectedPages($this->network) as $page) {
            if ($social->wasPosted($model, $this->network, $page)) {
                continue;
            }

            try {
                $social->shareToPage($model, $this->network, $page, $this->trigger);
            } catch (RequestFailed $e) {
                if ($e->isRetryable()) {
                    $retry ??= $e;
                } else {
                    $permanent ??= $e;
                }
            } catch (InvalidArgumentException|NotConnected|NetworkDisabled|UnknownNetwork $e) {
                $permanent ??= $e;
            }
        }

        if ($retry !== null) {
            throw $retry;
        }

        if ($permanent !== null) {
            $this->fail($permanent);
        }
    }

    /** One queued share per model and network: quick publish/unpublish/publish cycles cannot queue two posts. */
    public function uniqueId(): string
    {
        return "{$this->shareableType}:{$this->shareableId}:{$this->network}";
    }

    public function failed(Throwable $exception): void
    {
        SharedPost::query()->create([
            'shareable_type' => $this->shareableType,
            'shareable_id' => $this->shareableId,
            'network' => $this->network,
            'status' => SharedPost::STATUS_FAILED,
            'trigger' => $this->trigger,
            'error' => mb_substr($exception->getMessage(), 0, 2000),
            'posted_at' => null,
            'created_at' => CarbonImmutable::now(),
        ]);

        event(new ShareFailed($this->network, $this->shareableType, $this->shareableId, $exception));
    }

    private function resolve(): (Model&ShareableOnSocial)|null
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnSocial::class)) {
            return null;
        }

        /** @var (Model&ShareableOnSocial)|null */
        return $class::query()->find($this->shareableId);
    }
}
