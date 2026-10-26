<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Siberfx\SocialAutopost\Notifications\Notifier;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\NetworkNotes;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

/**
 * Checks the setup where it usually goes wrong: missing credentials, the
 * callback URL to register, connections and their expiry, the gate, the
 * tables, the queue and the notification channels. Exit code 1 when an
 * enabled network cannot work as configured.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'social:doctor {--ping : Ask each connected network about its connection}';

    protected $description = 'Check the Social Autopost setup and point out what is missing';

    /** The credentials each built-in network needs. A custom network is not checked. */
    private const CREDENTIALS = [
        'linkedin' => ['client_id', 'client_secret'],
        'x' => ['client_id'],
        'facebook' => ['app_id', 'app_secret'],
        'instagram' => ['client_id', 'client_secret'],
        'tiktok' => ['client_key', 'client_secret'],
        'mastodon' => ['instance', 'client_id', 'client_secret'],
    ];

    public function handle(SocialManager $social, Notifier $notifier): int
    {
        $problems = 0;

        if ($social->enabled()->isEmpty()) {
            $this->components->warn('No network is switched on: set LINKEDIN_ENABLED=true (or another network) in .env.');
        }

        foreach ($social->enabled() as $key) {
            $problems += $this->network($social, $key);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Application</>');
        $problems += $this->application($social, $notifier);

        $this->newLine();
        $this->components->info($problems === 0
            ? 'Everything checks out.'
            : "{$problems} problem(s) found. See above.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return int How many problems this network has. */
    private function network(SocialManager $social, string $key): int
    {
        $driver = $social->network($key);
        $problems = 0;

        $this->newLine();
        $this->components->twoColumnDetail("<options=bold>{$driver->label()}</>");

        $missing = array_values(array_filter(
            self::CREDENTIALS[$key] ?? [],
            function (string $name) use ($key): bool {
                $value = config("social-autopost.networks.{$key}.{$name}");

                return ! is_string($value) || $value === '';
            },
        ));

        if ($missing !== []) {
            $this->components->twoColumnDetail('  Credentials', '<fg=red>missing: '.implode(', ', $missing).'</>');
            $problems++;
        } else {
            $this->components->twoColumnDetail('  Credentials', array_key_exists($key, self::CREDENTIALS) ? 'OK' : 'not checked (custom network)');
        }

        $this->components->twoColumnDetail('  Register this callback', $this->redirectUri($key));

        if (! $social->isConnected($key)) {
            $this->components->twoColumnDetail('  Connection', '<fg=yellow>not connected</>');

            if ($social->autopostEnabled($key)) {
                $this->components->twoColumnDetail('  Auto-post', '<fg=yellow>on, but nothing posts until the network is connected</>');
            }

            return $problems;
        }

        if ($this->option('ping')) {
            try {
                $connection = $social->connection($key);
                $days = $connection->daysLeft();

                $this->components->twoColumnDetail('  Connection', sprintf('%s (%s%s)',
                    $connection->name ?? 'connected',
                    $connection->status ?? 'unknown',
                    $days !== null ? ", {$days} days left" : ''));

                if (in_array($connection->status, ['expired', 'revoked'], true)) {
                    $this->components->twoColumnDetail('  ', '<fg=red>connect again to keep posting</>');
                    $problems++;
                }
            } catch (Throwable $e) {
                $this->components->twoColumnDetail('  Connection', '<fg=red>'.$e->getMessage().'</>');
                $problems++;
            }
        } else {
            $this->components->twoColumnDetail('  Connection', 'stored (use --ping to ask the network)');
        }

        $this->components->twoColumnDetail('  Auto-post', $social->autopostEnabled($key) ? 'on' : 'off');

        if (($hint = NetworkNotes::hint($key)) !== null) {
            $this->components->twoColumnDetail('  Note', $hint);
        }

        return $problems;
    }

    /** @return int How many problems the app setup has. */
    private function application(SocialManager $social, Notifier $notifier): int
    {
        $problems = 0;

        $ability = SocialAutopostServiceProvider::ABILITY;
        $fallback = SocialAutopostServiceProvider::$fallbackGate;

        if ($fallback !== null && (Gate::abilities()[$ability] ?? null) === $fallback) {
            $this->components->twoColumnDetail('  Gate', "<fg=red>'{$ability}' is not defined: every route answers 403</>");
            $problems++;
        } else {
            $this->components->twoColumnDetail('  Gate', 'defined');
        }

        $tables = array_filter(['social_connections', 'social_posts'], fn (string $table) => ! Schema::hasTable($table));

        if ($tables !== []) {
            $this->components->twoColumnDetail('  Database', '<fg=red>missing table(s) '.implode(', ', $tables).': run php artisan migrate</>');
            $problems++;
        } elseif (! SocialManager::tracksPages()) {
            $this->components->twoColumnDetail('  Database', '<fg=yellow>social_posts has no target column: publish and run the 1.2 migration to record pages</>');
        } else {
            $this->components->twoColumnDetail('  Database', 'migrated');
        }

        $autoposting = $social->enabled()->filter(fn (string $key) => $social->autopostEnabled($key));

        if ($autoposting->isNotEmpty() && config('social-autopost.autopost.queue_connection', config('queue.default')) === 'sync') {
            $this->components->twoColumnDetail('  Queue', '<fg=yellow>sync: auto-posts run inside the request; run a queue worker</>');
        } else {
            $this->components->twoColumnDetail('  Queue', (string) config('social-autopost.autopost.queue_connection', config('queue.default')));
        }

        if ($notifier->enabled()) {
            $channels = $notifier->enabledChannels();
            $unconfigured = array_values(array_filter($channels, fn (string $name) => ! $notifier->channel($name)->configured()));

            $this->components->twoColumnDetail('  Notifications', match (true) {
                $channels === [] => '<fg=yellow>on, but no channel is switched on</>',
                $unconfigured !== [] => '<fg=yellow>channel(s) not configured: '.implode(', ', $unconfigured).'</>',
                default => 'on: '.implode(', ', $channels),
            });
        } else {
            $this->components->twoColumnDetail('  Notifications', 'off');
        }

        return $problems;
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
            return "set {$key}'s redirect_uri (routes are disabled)";
        }
    }
}
