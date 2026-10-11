<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One share of one model to one network: posted or failed.
 *
 * @property string $shareable_type
 * @property int|string $shareable_id
 * @property string $network
 * @property string|null $post_id
 * @property string $status
 * @property string $trigger
 * @property string|null $error
 * @property CarbonImmutable|null $posted_at
 */
final class SharedPost extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CONSOLE = 'console';

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
}
