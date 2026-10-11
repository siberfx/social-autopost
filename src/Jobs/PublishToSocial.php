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

    public int $tries;

    /** Seconds the unique lock may outlive a job that never ran. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $shareableType,
        public readonly int|string $shareableId,
        public readonly string $network,
        public readonly string $trigger = SharedPost::TRIGGER_AUTO,
    ) {
        $this->tries = (int) config('social-autopost.job.tries', 3);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('social-autopost.job.backoff', [60, 300])));
    }

    public function handle(SocialManager $social): void
    {
        $model = $this->resolve();

        // Unpublished, deleted, switched off or disconnected since the job was
        // queued, or already posted there: nothing to do.
        if ($model === null || ! $model->isLiveForSocial() || ! $social->isEnabled($this->network)
            || ! $social->isConnected($this->network) || $social->wasPosted($model, $this->network)) {
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
