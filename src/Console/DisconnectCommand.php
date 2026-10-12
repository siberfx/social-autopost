<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\NetworkNotes;

final class DisconnectCommand extends Command
{
    protected $signature = 'social:disconnect {network : The network key, e.g. linkedin} {--force : Do not ask for confirmation}';

    protected $description = 'Revoke and forget one social network connection';

    public function handle(SocialManager $social): int
    {
        $key = $this->stringArgument('network');

        if (! $social->isEnabled($key)) {
            $this->components->error("The [{$key}] network is switched off or unknown.");

            return self::FAILURE;
        }

        $label = $social->network($key)->label();

        if (! $social->isConnected($key)) {
            $this->components->info("{$label} is not connected.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Disconnect {$label} and revoke the connection?")) {
            return self::FAILURE;
        }

        $revoked = $social->disconnect($key);

        $note = NetworkNotes::disconnectNote($key);

        match (true) {
            $revoked => $this->components->info("Disconnected. {$label} revoked the connection."),
            $note !== null => $this->components->warn("Disconnected here. {$note}"),
            default => $this->components->warn("Disconnected here, but {$label} did not confirm revoking the connection."),
        };

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
