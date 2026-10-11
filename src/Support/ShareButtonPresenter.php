<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;

/**
 * The share button for one model. With a network it shares there only; with
 * none, to every enabled and connected network.
 */
final class ShareButtonPresenter
{
    private ?CarbonImmutable $lastPostedAt = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Model&ShareableOnSocial $model,
        private readonly SocialManager $social,
        private readonly ?string $network = null,
        private readonly ?string $label = null,
    ) {}

    public function lastPostedAt(): ?CarbonImmutable
    {
        if (! $this->resolved) {
            $this->lastPostedAt = SharedPost::query()
                ->for($this->model)
                ->posted()
                ->when($this->network !== null, fn ($query) => $query->forNetwork((string) $this->network))
                ->latest('posted_at')
                ->first()?->posted_at;
            $this->resolved = true;
        }

        return $this->lastPostedAt;
    }

    public function disabledReason(): ?string
    {
        if (Gate::denies(SocialAutopostServiceProvider::ABILITY)) {
            return 'You are not allowed to manage social networks.';
        }

        if ($this->network !== null) {
            if (! $this->social->isEnabled($this->network)) {
                return 'That network is switched off.';
            }

            if (! $this->social->isConnected($this->network)) {
                return 'Connect '.$this->social->network($this->network)->label().' first.';
            }
        } elseif ($this->social->targets()->isEmpty()) {
            return 'Connect a network first.';
        }

        return $this->model->isLiveForSocial() ? null : 'Only live items can be shared.';
    }

    /** The network's logo, or the generic one when the button shares to all networks. */
    public function logo(): string
    {
        return NetworkBrand::logo((string) $this->network);
    }

    public function text(): string
    {
        $again = $this->lastPostedAt() !== null;

        if ($this->label !== null) {
            return $this->label;
        }

        if ($this->network === null) {
            return $again ? 'Share again' : 'Share';
        }

        $name = $this->social->network($this->network)->label();

        return $again ? "Share again on {$name}" : "Share on {$name}";
    }

    public function title(): string
    {
        $posted = $this->lastPostedAt();

        return $this->disabledReason()
            ?? ($posted !== null ? 'Shared on '.$posted->format('j M Y').'. Share again.' : 'Share');
    }

    public function confirmText(): string
    {
        $posted = $this->lastPostedAt();

        return $posted !== null
            ? 'This was already posted on '.$posted->format('j M Y').'. Post it again?'
            : 'Post this now?';
    }

    /** The signed share URL. The network is part of it when the button targets one. */
    public function signedAction(): string
    {
        $routes = config('social-autopost.routes.name', 'social-autopost.');
        $parameters = ['type' => $this->model->getMorphClass(), 'id' => $this->model->getKey()];

        if ($this->network === null) {
            return URL::signedRoute($routes.'share', $parameters);
        }

        return URL::signedRoute($routes.'share.network', ['network' => $this->network] + $parameters);
    }
}
