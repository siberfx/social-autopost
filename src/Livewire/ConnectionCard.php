<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Component;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\ConnectionViewData;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Theme;

/** One network's connection card; the actions check the same gate as the routes. */
final class ConnectionCard extends Component
{
    public string $network = '';

    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(string $network): void
    {
        $this->network = $network;
        $this->flash = StatusMessages::current(request());
    }

    public function disconnect(SocialManager $social): void
    {
        Gate::authorize(SocialAutopostServiceProvider::ABILITY);
        abort_unless($social->isEnabled($this->network), 404);

        $label = $social->network($this->network)->label();
        $revoked = $social->disconnect($this->network);

        $this->flash = ['type' => 'success', 'message' => $revoked
            ? "{$label} disconnected."
            : "{$label} disconnected here, but {$label} did not confirm revoking the connection."];
    }

    public function render(SocialManager $social): View
    {
        $data = ConnectionViewData::for($this->network, $social);

        return view(Theme::view($data === null ? 'empty' : 'connection'), ($data ?? []) + [
            'flash' => $this->flash,
            'livewire' => true,
            'attributes' => new ComponentAttributeBag,
        ]);
    }
}
