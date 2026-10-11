<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Theme;

/** <x-social-autopost::flash /> — the message from the last connect or share. */
final class Flash extends Component
{
    public function render(): View
    {
        return view(Theme::view('flash'), ['flash' => StatusMessages::current(request())]);
    }
}
