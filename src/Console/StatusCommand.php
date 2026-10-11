<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\SocialAutopost\SocialManager;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'social:status';

    protected $description = 'Show every social network: switched on, auto-post, connected account and token status';

    public function handle(SocialManager $social): int
    {
        $rows = [];

        foreach ($social->keys() as $key) {
            $network = $social->network($key);
            $enabled = $social->isEnabled($key);
            $connected = $enabled && $social->isConnected($key);

            $status = match (true) {
                ! $enabled => 'switched off',
                ! $connected => 'not connected',
                default => $this->connectionStatus($key),
            };

            $rows[] = [
                $network->label(),
                $key,
                $enabled ? 'yes' : 'no',
                $social->autopostEnabled($key) ? 'yes' : 'no',
                $connected ? 'yes' : 'no',
                $status,
            ];
        }

        $this->table(['Network', 'Key', 'Enabled', 'Auto-post', 'Connected', 'Status'], $rows);

        return self::SUCCESS;
    }

    private function connectionStatus(string $key): string
    {
        try {
            $connection = app(SocialManager::class)->connection($key);
        } catch (Throwable $e) {
            return 'error: '.$e->getMessage();
        }

        $days = $connection->daysLeft();

        return ($connection->status ?? 'unknown').($days !== null ? " ({$days} days left)" : '');
    }
}
