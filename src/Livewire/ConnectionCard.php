<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;
use Livewire\Component;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\ConnectionViewData;
use Siberfx\SocialAutopost\Support\NetworkNotes;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Theme;

/** One network's connection card; the actions check the same gate as the routes. */
final class ConnectionCard extends Component
{
    public string $network = '';

    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    /** @var list<string> The page ids ticked in the page picker. */
    public array $selectedPages = [];

    public function mount(string $network, SocialManager $social): void
    {
        $this->network = $network;
        $this->flash = StatusMessages::current(request());

        if ($social->isEnabled($network) && $social->hasPages($network)) {
            $this->selectedPages = $social->selectedPages($network);
        }
    }

    public function disconnect(SocialManager $social): void
    {
        Gate::authorize(SocialAutopostServiceProvider::ABILITY);
        abort_unless($social->isEnabled($this->network), 404);

        $label = $social->network($this->network)->label();
        $revoked = $social->disconnect($this->network);

        $this->flash = ['type' => 'success', 'message' => NetworkNotes::disconnected($this->network, $label, $revoked)];
    }

    /** Saves the ticked pages ($selectedPages) as the pages shares go to. */
    public function selectPages(SocialManager $social): void
    {
        Gate::authorize(SocialAutopostServiceProvider::ABILITY);
        abort_unless($social->isEnabled($this->network) && $social->hasPages($this->network), 404);

        $label = $social->network($this->network)->label();

        try {
            $social->selectPages($this->network, array_values(array_filter($this->selectedPages, fn (string $id) => $id !== '')));
            $this->flash = ['type' => 'success', 'message' => "{$label} will post to the chosen pages."];
        } catch (InvalidArgumentException|NotConnected $e) {
            $this->flash = ['type' => 'error', 'message' => $e->getMessage()];
        }
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
