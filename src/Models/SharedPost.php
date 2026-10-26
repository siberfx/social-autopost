<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One share of one model to one network (and page, for a network with pages): posted or failed.
 *
 * @property string $shareable_type
 * @property int|string $shareable_id
 * @property string $network
 * @property string|null $target The page posted to, for a network with pages (see HasPages).
 * @property string|null $post_id
 * @property string $status
 * @property string $trigger
 * @property string|null $error
 * @property CarbonImmutable|null $posted_at
 */
final class SharedPost extends Model
{
    use MassPrunable;

    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CONSOLE = 'console';

    public const TRIGGER_SCHEDULED = 'scheduled';

    protected $table = 'social_posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What model:prune deletes: history older than prune_after_days; nothing
     * when that is null. A pruned share is forgotten, so wasPosted() no longer
     * sees it and sharing the model again posts again.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = config('social-autopost.prune_after_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()->where('created_at', '<', now()->subDays((int) $days));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFor(Builder $query, Model $model): Builder
    {
        return $query->where('shareable_type', $model->getMorphClass())
            ->where('shareable_id', $model->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForNetwork(Builder $query, string $network): Builder
    {
        return $query->where('network', $network);
    }

    /**
     * Shares to one page of a network with pages. Needs the target column
     * (update_social_posts_table_add_target migration).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTarget(Builder $query, string $target): Builder
    {
        return $query->where('target', $target);
    }
}
