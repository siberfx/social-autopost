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

        foreach ($social->keys() as $key) {
            if ($social->isEnabled($key) && $social->hasPages($key) && $social->isConnected($key)) {
                $this->pages($social, $key);
            }
        }

        return self::SUCCESS;
    }

    /** The pages a network can post to; [x] marks the ones shares go to. */
    private function pages(SocialManager $social, string $key): void
    {
        $selected = $social->selectedPages($key);

        $this->newLine();
        $this->line($social->network($key)->label().' pages ([x] = shares go there):');

        foreach ($social->pages($key) as $page) {
            $mark = in_array($page->id, $selected, true) ? '[x]' : '[ ]';
            $this->line("  {$mark} ".($page->name ?? $page->id)." ({$page->type}) {$page->id}");
        }
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
