<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;

it('fails and names the missing credentials of an enabled network', function () {
    $this->network('linkedin');
    config()->set('social-autopost.networks.linkedin.client_id', null);
    config()->set('social-autopost.networks.linkedin.client_secret', 'secret');

    $this->artisan('social:doctor')
        ->expectsOutputToContain('missing: client_id')
        ->assertFailed();
});

it('checks out with credentials, a connection and a defined gate', function () {
    $this->network('linkedin');
    config()->set('social-autopost.networks.linkedin.client_id', 'id');
    config()->set('social-autopost.networks.linkedin.client_secret', 'secret');
    $this->connect('linkedin');

    $this->artisan('social:doctor')
        ->expectsOutputToContain('Everything checks out')
        ->assertSuccessful();
});

it('points out an undefined gate', function () {
    // Re-register the deny-all fallback, as an app that never defined the gate.
    Gate::define(SocialAutopostServiceProvider::ABILITY, SocialAutopostServiceProvider::$fallbackGate = fn ($user = null): bool => false);

    $this->artisan('social:doctor')
        ->expectsOutputToContain('is not defined')
        ->assertFailed();
});

it('warns when auto-post is on but the network is not connected', function () {
    $this->network('fake', true, true);

    $this->artisan('social:doctor')
        ->expectsOutputToContain('nothing posts until the network is connected')
        ->assertSuccessful();
});
