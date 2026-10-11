<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\ShareButtonPresenter;
use Siberfx\SocialAutopost\Support\Theme;

/** <x-social-autopost::share-button :model="$article" network="x" /> — omit network to share to every enabled, connected network. */
final class ShareButton extends Component
{
    public function __construct(
        public Model $model,
        public ?string $network = null,
        public ?string $label = null,
        public string $size = 'md',
        public bool $confirm = true,
    ) {
        if (! $model instanceof ShareableOnSocial) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnSocial::class.'.');
        }
    }

    public function render(): View
    {
        $social = app(SocialManager::class);

        if ($this->network !== null && ! $social->isEnabled($this->network)) {
            return view(Theme::view('empty'));
        }

        /** @var Model&ShareableOnSocial $model */
        $model = $this->model;

        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($model, $social, $this->network, $this->label),
            'size' => $this->size === 'sm' ? 'sm' : 'md',
            'confirm' => $this->confirm,
            'livewire' => false,
            'flash' => null,
        ]);
    }
}
