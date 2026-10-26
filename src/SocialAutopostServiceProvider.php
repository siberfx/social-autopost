<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Siberfx\SocialAutopost\Console\CheckTokenCommand;
use Siberfx\SocialAutopost\Console\DisconnectCommand;
use Siberfx\SocialAutopost\Console\DoctorCommand;
use Siberfx\SocialAutopost\Console\InstallCommand;
use Siberfx\SocialAutopost\Console\PageCommand;
use Siberfx\SocialAutopost\Console\RetryCommand;
use Siberfx\SocialAutopost\Console\ShareCommand;
use Siberfx\SocialAutopost\Console\StatusCommand;
use Siberfx\SocialAutopost\Console\TestNotificationCommand;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Events\Shared;
use Siberfx\SocialAutopost\Events\ShareFailed;
use Siberfx\SocialAutopost\Events\TokenExpiringSoon;
use Siberfx\SocialAutopost\Listeners\SendNotifications;
use Siberfx\SocialAutopost\Support\Ui;

final class SocialAutopostServiceProvider extends ServiceProvider
{
    /** Who may connect, disconnect, see the connections and share. Define it in your app. */
    public const ABILITY = 'manage-social-autopost';

    /** The deny-all fallback gate, when it was registered: social:doctor checks whether the app replaced it. */
    public static ?\Closure $fallbackGate = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social-autopost.php', 'social-autopost');

        $this->app->bind(
            ConnectionStore::class,
            fn ($app) => $app->make((string) config('social-autopost.connection_store')),
        );

        $this->app->scoped(SocialManager::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'social-autopost');

        // Deny by default. Registered once every provider has booted, so the
        // app's own definition wins whatever the provider order.
        $this->app->booted(function (): void {
            if (! Gate::has(self::ABILITY)) {
                Gate::define(self::ABILITY, self::$fallbackGate = fn ($user = null): bool => false);
            }
        });

        // Always listening; the listener reads social-autopost.notifications on each event.
        Event::listen([Shared::class, ShareFailed::class, TokenExpiringSoon::class], SendNotifications::class);

        // Spaces queued shares out per network (networks.{key}.rate_limit).
        RateLimiter::for(Jobs\PublishToSocial::LIMITER, function (Jobs\PublishToSocial $job): Limit {
            $limit = Jobs\PublishToSocial::rateLimit($job->network);

            return $limit === null
                ? Limit::none()
                : (new Limit('', $limit['posts'], $limit['per_seconds']))->by($job->network);
        });

        if (Ui::enabled()) {
            $this->bootUi();
        }

        if (config('social-autopost.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/social-autopost.php');
        }

        // Expiry warnings without any setup: the token check runs on the
        // app's scheduler (check_token_schedule, null to schedule it yourself).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = config('social-autopost.check_token_schedule');

            if (is_string($cron) && $cron !== '') {
                $schedule->command('social:check-token')->cron($cron);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/social-autopost.php' => config_path('social-autopost.php'),
            ], 'social-autopost-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'social-autopost-migrations');

            $this->publishes([
                __DIR__.'/../stubs/ShareableModel.stub' => base_path('stubs/social-autopost/ShareableModel.stub'),
            ], 'social-autopost-stubs');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/social-autopost'),
            ], 'social-autopost-lang');

            $this->commands([
                InstallCommand::class,
                PageCommand::class,
                StatusCommand::class,
                DoctorCommand::class,
                DisconnectCommand::class,
                ShareCommand::class,
                RetryCommand::class,
                CheckTokenCommand::class,
                TestNotificationCommand::class,
            ]);
        }
    }

    /** Views, Blade and Livewire components; skipped in headless mode (ui.enabled = false). */
    private function bootUi(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'social-autopost');
        Blade::componentNamespace('Siberfx\SocialAutopost\View\Components', 'social-autopost');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('social-autopost.connection', Livewire\ConnectionCard::class);
            \Livewire\Livewire::component('social-autopost.share-button', Livewire\ShareButton::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/social-autopost'),
            ], 'social-autopost-views');
        }
    }
}
