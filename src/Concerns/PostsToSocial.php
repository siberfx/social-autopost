<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\Observers\ShareableObserver;

/** For models implementing ShareableOnSocial: auto-post and share history on every network. */
trait PostsToSocial
{
    public static function bootPostsToSocial(): void
    {
        // Not static::observe(): that instantiates the model, which throws
        // while the model is still booting.
        static::saved(static fn ($model) => app(ShareableObserver::class)->saved($model));
    }

    /** @return MorphMany<SharedPost, $this> */
    public function socialPosts(): MorphMany
    {
        return $this->morphMany(SharedPost::class, 'shareable');
    }

    public function wasPostedTo(string $network): bool
    {
        return $this->socialPosts()->where('network', $network)->where('status', SharedPost::STATUS_POSTED)->exists();
    }

    public function wasPostedToSocial(): bool
    {
        return $this->socialPosts()->where('status', SharedPost::STATUS_POSTED)->exists();
    }
}
