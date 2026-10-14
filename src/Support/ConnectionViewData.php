<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Illuminate\Support\Facades\Gate;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;

/** The variables the connection card views need, shared by the Blade and Livewire versions. */
final class ConnectionViewData
{
    /** @return array<string, mixed>|null Null when the network is switched off: the card then renders nothing. */
    public static function for(string $network, SocialManager $social): ?array
    {
        if (! $social->isEnabled($network)) {
            return null;
        }

        $label = $social->network($network)->label();
        $connection = $social->connection($network);
        $routes = (string) config('social-autopost.routes.name', 'social-autopost.');

        return [
            'network' => $network,
            'label' => $label,
            'brand' => NetworkBrand::for($network),
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection, $label),
            'hint' => NetworkNotes::hint($network),
            'confirmText' => NetworkNotes::confirmDisconnect($network, $label),
            'canManage' => Gate::allows(SocialAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect', ['network' => $network]),
            'disconnectUrl' => route($routes.'connection.destroy', ['network' => $network]),
            // The page picker shows when there is a choice to make.
            'pagesUrl' => $social->hasPages($network) && count($connection->pages) > 1
                ? route($routes.'pages.update', ['network' => $network])
                : null,
        ];
    }
}
