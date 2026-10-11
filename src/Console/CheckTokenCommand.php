<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\SocialAutopost\Events\TokenExpiringSoon;
use Siberfx\SocialAutopost\SocialManager;
use Throwable;

final class CheckTokenCommand extends Command
{
    protected $signature = 'social:check-token {--days= : Warn when fewer days remain (default from config)}';

    protected $description = 'Fire TokenExpiringSoon for each enabled network whose connection is about to expire';

    public function handle(SocialManager $social): int
    {
        $days = (int) ($this->option('days') ?? config('social-autopost.expiry_warning_days', 7));

        foreach ($social->enabled() as $key) {
            if (! $social->isConnected($key)) {
                continue;
            }

            try {
                $connection = $social->connection($key);
            } catch (Throwable $e) {
                $this->components->error("{$key}: {$e->getMessage()}");

                continue;
            }

            $label = $social->network($key)->label();
            $left = $connection->daysLeft();
            $dead = in_array($connection->status, ['expired', 'revoked'], true);

            if ($left === null && ! $dead) {
                $this->components->info("{$label}: the expiry is unknown.");

                continue;
            }

            $left ??= 0;

            if ($dead || $left <= $days) {
                event(new TokenExpiringSoon($key, $connection, $left));
                $this->components->warn("{$label}: the connection expires in {$left} days. Connect again to renew it.");

                continue;
            }

            $this->components->info("{$label}: valid for {$left} more days.");
        }

        return self::SUCCESS;
    }
}
