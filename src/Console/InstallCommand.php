<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\Ui;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

final class InstallCommand extends Command
{
    private const THEMES = ['tailwind', 'bootstrap'];

    protected $signature = 'social:install
        {--theme= : tailwind or bootstrap}
        {--headless : No UI: skip the theme, set SOCIAL_UI=false and use the facade, events and JSON endpoints}';

    protected $description = 'Publish the Social Autopost config and migrations and choose the UI theme';

    public function handle(SocialManager $social): int
    {
        $headless = (bool) $this->option('headless') || ! Ui::enabled();
        $theme = null;

        if (! $headless) {
            $theme = $this->option('theme') ?? $this->choice('Which admin UI theme do you use?', self::THEMES, 0);

            if (! in_array($theme, self::THEMES, true)) {
                $this->components->error('Choose tailwind or bootstrap.');

                return self::FAILURE;
            }
        }

        $this->callSilently('vendor:publish', ['--tag' => 'social-autopost-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'social-autopost-migrations']);
        $this->components->info('Published config/social-autopost.php and the migrations.');

        if ($headless) {
            $this->writeEnv('SOCIAL_UI', 'false');
        } else {
            $this->writeEnv('SOCIAL_UI_THEME', (string) $theme);
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Switch on the networks you use in .env, e.g. LINKEDIN_ENABLED=true, X_ENABLED=true, FACEBOOK_ENABLED=true, INSTAGRAM_ENABLED=true, TIKTOK_ENABLED=true');
        $this->line('  3. Set each network\'s credentials (see config/social-autopost.php), then register its redirect URI:');

        $enabled = $social->enabled();

        if ($enabled->isEmpty()) {
            $this->line('       (no network is switched on yet)');
        }

        foreach ($enabled as $key) {
            $this->line('       '.$social->network($key)->label().': '.$this->redirectUri($key));
        }

        $this->line('  4. Define who may manage the networks, e.g. in AppServiceProvider::boot() (everyone is refused until you do):');
        $this->line("     Gate::define('manage-social-autopost', fn (User \$user) => \$user->is_admin);");
        $this->line($headless
            ? '  5. Send your admins to route(\'social-autopost.redirect\', \'linkedin\') to connect; share with Social::share($model) or the JSON endpoints'
            : '  5. Put <x-social-autopost::connection network="linkedin" /> on your admin settings page, one per network');
        $this->line('  6. Optional: Telegram, Slack or email messages on each post, see "notifications" in config/social-autopost.php');

        if ($theme === 'tailwind') {
            $this->newLine();
            $this->line('Tailwind v4: add to your CSS so the package classes are compiled:');
            $this->line("  @source '../../vendor/siberfx/social-autopost/resources/views/tailwind';");
            $this->line("Tailwind v3: add './vendor/siberfx/social-autopost/resources/views/tailwind/**/*.blade.php' to content in tailwind.config.js.");
        }

        return self::SUCCESS;
    }

    private function redirectUri(string $key): string
    {
        $configured = config("social-autopost.networks.{$key}.redirect_uri");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        try {
            return route(config('social-autopost.routes.name', 'social-autopost.').'callback', ['network' => $key]);
        } catch (RouteNotFoundException) {
            // Routes disabled and no redirect_uri: there is no callback URL to show.
            return "set {$key}'s redirect_uri in .env (routes are disabled)";
        }
    }

    private function writeEnv(string $key, string $value): void
    {
        $env = $this->laravel->environmentFilePath();

        if (! File::exists($env)) {
            $this->components->warn("No .env found; add {$key}={$value} yourself.");

            return;
        }

        $contents = File::get($env);

        if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
            $this->components->info("{$key} is already set in .env; left unchanged.");

            return;
        }

        $separator = $contents === '' || str_ends_with($contents, "\n") ? '' : "\n";
        File::append($env, "{$separator}{$key}={$value}\n");
        $this->components->info("Added {$key}={$value} to .env.");
    }
}
