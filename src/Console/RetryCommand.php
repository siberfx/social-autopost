<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;
use Siberfx\SocialAutopost\Jobs\PublishToSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Throwable;

/**
 * Queues the failed shares that never posted, so they are tried again. A
 * failure whose model has since posted to that network (and page) is settled
 * and left alone; the queued job re-checks everything again when it runs.
 */
final class RetryCommand extends Command
{
    protected $signature = 'social:retry
        {network? : Only this network}
        {--since= : Only failures on or after this date or time}
        {--dry-run : List what would be queued without queueing it}';

    protected $description = 'Queue the failed shares that never posted, so they are tried again';

    public function handle(SocialManager $social): int
    {
        $network = $this->argument('network');
        $network = is_string($network) && $network !== '' ? $network : null;

        if ($network !== null && ! $social->isEnabled($network)) {
            $this->components->error("The [{$network}] network is switched off or unknown.");

            return self::FAILURE;
        }

        $option = $this->option('since');
        $option = is_string($option) && $option !== '' ? $option : null;

        try {
            $since = $option !== null ? CarbonImmutable::parse($option) : null;
        } catch (Throwable) {
            $this->components->error("Could not read [{$option}] as a date or time.");

            return self::FAILURE;
        }

        $failures = SharedPost::query()
            ->where('status', SharedPost::STATUS_FAILED)
            ->when($network !== null, fn (Builder $query) => $query->where('network', $network))
            ->when($since !== null, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->whereNotExists(fn (Builder $query) => $this->settled($query))
            ->orderBy('id')
            ->get()
            ->unique(fn (SharedPost $row) => "{$row->shareable_type}:{$row->shareable_id}:{$row->network}")
            ->values();

        $queued = 0;

        foreach ($failures as $failure) {
            if (! $social->isEnabled($failure->network) || ! $social->isConnected($failure->network)) {
                $this->components->warn("{$failure->network}: switched off or not connected, skipping {$failure->shareable_type} {$failure->shareable_id}.");

                continue;
            }

            $this->components->twoColumnDetail("{$failure->shareable_type} {$failure->shareable_id} → {$failure->network}",
                $this->option('dry-run') ? 'would queue' : 'queued');

            if (! $this->option('dry-run')) {
                dispatch((new PublishToSocial($failure->shareable_type, $failure->shareable_id, $failure->network, SharedPost::TRIGGER_CONSOLE))
                    ->onConnection(config('social-autopost.autopost.queue_connection'))
                    ->onQueue(config('social-autopost.autopost.queue')));
            }

            $queued++;
        }

        $this->components->info(match (true) {
            $queued === 0 => 'Nothing to retry.',
            (bool) $this->option('dry-run') => "{$queued} failed share(s) would be queued.",
            default => "Queued {$queued} failed share(s) to retry.",
        });

        return self::SUCCESS;
    }

    /**
     * A posted row that settles the failure: same model and network, and the
     * same page when the failure named one (and pages are recorded at all).
     */
    private function settled(Builder $query): Builder
    {
        $table = (new SharedPost)->getTable();

        $query->from("{$table} as settled")
            ->selectRaw('1')
            ->whereColumn('settled.shareable_type', "{$table}.shareable_type")
            ->whereColumn('settled.shareable_id', "{$table}.shareable_id")
            ->whereColumn('settled.network', "{$table}.network")
            ->where('settled.status', SharedPost::STATUS_POSTED);

        if (SocialManager::tracksPages()) {
            $query->where(fn (Builder $page) => $page
                ->whereNull("{$table}.target")
                ->orWhereColumn('settled.target', "{$table}.target"));
        }

        return $query;
    }
}
