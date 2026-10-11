<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Stores;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Models\SocialConnection;

/** Stores each network's data as one encrypted JSON payload per network. */
final class DatabaseConnectionStore implements ConnectionStore
{
    public function get(string $network): ?array
    {
        $row = SocialConnection::query()->where('network', $network)->first();

        if ($row === null) {
            return null;
        }

        try {
            $json = Crypt::decryptString($row->payload);
        } catch (DecryptException) {
            // APP_KEY changed since connecting: the data is unreadable, so the
            // network is effectively disconnected and must connect again.
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    public function put(string $network, array $data): void
    {
        SocialConnection::query()->updateOrCreate(
            ['network' => $network],
            ['payload' => Crypt::encryptString((string) json_encode($data, JSON_THROW_ON_ERROR))],
        );
    }

    public function forget(string $network): void
    {
        SocialConnection::query()->where('network', $network)->delete();
    }
}
