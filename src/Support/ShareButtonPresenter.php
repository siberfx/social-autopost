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
            return Lines::get('not_allowed');
        }

        if ($this->network !== null) {
            if (! $this->social->isEnabled($this->network)) {
                return Lines::get('network_off');
            }

            if (! $this->social->isConnected($this->network)) {
                return Lines::get('connect_first', ['label' => $this->social->network($this->network)->label()]);
            }
        } elseif ($this->social->targets()->isEmpty()) {
            return Lines::get('connect_any_first');
        }

        return $this->model->isLiveForSocial() ? null : Lines::get('only_live');
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
            return $again ? Lines::get('share_again') : Lines::get('share');
        }

        $name = $this->social->network($this->network)->label();

        return $again
            ? Lines::get('share_again_on', ['label' => $name])
            : Lines::get('share_on', ['label' => $name]);
    }

    public function title(): string
    {
        $posted = $this->lastPostedAt();

        return $this->disabledReason()
            ?? ($posted !== null
                ? Lines::get('shared_on_date', ['date' => $posted->format('j M Y')])
                : Lines::get('share'));
    }

    public function confirmText(): string
    {
        $posted = $this->lastPostedAt();

        return $posted !== null
            ? Lines::get('confirm_share_again', ['date' => $posted->format('j M Y')])
            : Lines::get('confirm_share');
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
