<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\NotShareable;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\ShareButtonPresenter;
use Siberfx\SocialAutopost\Support\Theme;

/** Share one model to one network, or to all enabled and connected ones when $network is empty. */
final class ShareButton extends Component
{
    #[Locked]
    public string $shareableType = '';

    #[Locked]
    public string $shareableId = '';

    #[Locked]
    public string $network = '';

    public ?string $label = null;

    public string $size = 'md';

    public bool $confirm = true;

    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(string $modelType, string $modelId, string $network = '', ?string $label = null, string $size = 'md', bool $confirm = true): void
    {
        $this->shareableType = $modelType;
        $this->shareableId = $modelId;
        $this->network = $network;
        $this->label = $label;
        $this->size = $size === 'sm' ? 'sm' : 'md';
        $this->confirm = $confirm;

        // Fail early on a bad type, not on the first click.
        $this->model();
    }

    public function share(SocialManager $social): void
    {
        Gate::authorize(SocialAutopostServiceProvider::ABILITY);

        try {
            $records = $social->share($this->model(), $this->network !== '' ? $this->network : null, SharedPost::TRIGGER_MANUAL);
        } catch (NotShareable|NotConnected|NetworkDisabled|RequestFailed|InvalidArgumentException $e) {
            $this->flash = ['type' => 'error', 'message' => $e->getMessage()];

            return;
        }

        $posted = $records->filter(fn (SharedPost $record) => $record->status === SharedPost::STATUS_POSTED)->pluck('network')->implode(', ');

        $this->flash = $posted !== ''
            ? ['type' => 'success', 'message' => "Shared on {$posted}."]
            : ['type' => 'error', 'message' => 'The share failed on every network.'];

        $this->dispatch('social-autopost-shared', networks: $posted);
    }

    public function render(SocialManager $social): View
    {
        $network = $this->network !== '' ? $this->network : null;

        if ($network !== null && ! $social->isEnabled($network)) {
            return view(Theme::view('empty'));
        }

        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($this->model(), $social, $network, $this->label),
            'size' => $this->size,
            'confirm' => $this->confirm,
            'livewire' => true,
            'flash' => $this->flash,
        ]);
    }

    /** @return Model&ShareableOnSocial */
    private function model(): Model
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        abort_if(! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnSocial::class), 404);

        /** @var Model&ShareableOnSocial */
        return $class::query()->findOrFail($this->shareableId);
    }
}
