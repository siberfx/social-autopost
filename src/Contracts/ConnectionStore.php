<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Contracts;

/**
 * Keeps each network's connection data. The array shape belongs to the network;
 * the store only keeps it safe (encrypted) and returns it unchanged.
 */
interface ConnectionStore
{
    /** @return array<string, mixed>|null The stored data, or null when not connected (or unreadable). */
    public function get(string $network): ?array;

    /** @param  array<string, mixed>  $data  Replaces any stored data for this network. */
    public function put(string $network, array $data): void;

    public function forget(string $network): void;
}
