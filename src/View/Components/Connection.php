<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\ConnectionViewData;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Theme;

/** <x-social-autopost::connection network="linkedin" /> — one card per network; renders nothing when it is switched off. */
final class Connection extends Component
{
    public function __construct(public string $network) {}

    public function render(): View
    {
        $data = ConnectionViewData::for($this->network, app(SocialManager::class));

        if ($data === null) {
            return view(Theme::view('empty'));
        }

        return view(Theme::view('connection'), $data + [
            'flash' => StatusMessages::current(request(), $data['label']),
            'livewire' => false,
        ]);
    }
}
