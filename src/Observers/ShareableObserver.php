<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Observers;

use Illuminate\Database\Eloquent\Model;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\SocialManager;

/**
 * Queues an auto-post when a model becomes live, once per network that is
 * enabled, switched to autopost, connected, and not posted there yet.
 */
final class ShareableObserver
{
    public function saved(Model $model): void
    {
        if (! $model instanceof ShareableOnSocial) {
            return;
        }

        if (app()->runningInConsole() && ! config('social-autopost.autopost.in_console')) {
            return;
        }

        if (! $model->isLiveForSocial() || ! $this->justBecameLive($model)) {
            return;
        }

        $social = app(SocialManager::class);

        foreach ($social->enabled() as $network) {
            if (! $social->autopostEnabled($network) || ! $social->isConnected($network) || $social->wasPosted($model, $network)) {
                continue;
            }

            $social->queue($model, $network, 'auto');
        }
    }

    /**
     * Inside "saved" the original attributes still hold the values from
     * before this save. Evaluate the model's own rule on them, so any live
     * rule (enum, boolean, dates) works without naming columns.
     */
    private function justBecameLive(Model&ShareableOnSocial $model): bool
    {
        $before = $model->getRawOriginal();

        if ($before === []) {
            return true; // a fresh insert
        }

        $previous = (clone $model)->setRawAttributes($before);

        return ! $previous->isLiveForSocial();
    }
}
