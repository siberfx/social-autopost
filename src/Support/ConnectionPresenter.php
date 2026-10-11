<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Siberfx\SocialAutopost\Data\Connection;

final class ConnectionPresenter
{
    public function __construct(
        public readonly Connection $connection,
        public readonly string $label,
    ) {}

    /** @return array{label: string, tone: 'success'|'warning'|'danger'|'neutral'} */
    public function badge(): array
    {
        $days = $this->connection->daysLeft();

        return match (true) {
            $this->connection->status === 'expired' => ['label' => 'Expired', 'tone' => 'danger'],
            $this->connection->status === 'revoked' => ['label' => "Revoked on {$this->label}", 'tone' => 'danger'],
            $this->connection->status === 'unknown' => ['label' => 'Status unknown', 'tone' => 'neutral'],
            $days !== null && $days < 14 => ['label' => 'Expires in '.max(0, $days).' days', 'tone' => 'warning'],
            default => ['label' => 'Connected', 'tone' => 'success'],
        };
    }

    public function needsReconnect(): bool
    {
        return in_array($this->badge()['tone'], ['warning', 'danger'], true);
    }

    public function displayName(): string
    {
        return $this->connection->name ?? "{$this->label} account";
    }

    public function initials(): string
    {
        $parts = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($this->displayName())) ?: [])), 0, 2);

        return mb_strtoupper(implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), $parts)));
    }

    public function connectedOn(): ?string
    {
        return $this->connection->connectedAt?->format('j M Y');
    }

    public function validUntil(): ?string
    {
        return $this->connection->expiresAt?->format('j M Y');
    }
}
