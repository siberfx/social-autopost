<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\UnknownNetwork;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\Tests\Fixtures\FakeNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

it('lists every configured network and only the switched-on ones as enabled', function () {
    expect(Social::keys())->toContain('linkedin', 'x', 'facebook', 'fake', 'broken');
    expect(Social::enabled()->all())->toBe([]);

    $this->network('fake');

    expect(Social::enabled()->all())->toBe(['fake']);
    expect(Social::isEnabled('broken'))->toBeFalse();
});

it('refuses an unknown network', function () {
    Social::network('myspace');
})->throws(UnknownNetwork::class);

it('refuses to connect or disconnect a switched-off network', function () {
    Social::connect('fake');
})->throws(NetworkDisabled::class);

it('refuses to share to a named network that is switched off', function () {
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::share($post, 'fake');
})->throws(NetworkDisabled::class);

it('refuses to share to a named network that is not connected', function () {
    $this->network('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    Social::share($post, 'fake');
})->throws(NotConnected::class);

it('skips switched-off networks when sharing to all', function () {
    $this->network('fake');
    $this->connect('fake');
    $this->connect('broken'); // connected, but switched off

    $post = Post::create(['title' => 'Hello', 'status' => 'published']);
    $records = Social::share($post);

    expect($records->pluck('network')->all())->toBe(['fake']);
    expect(SharedPost::query()->where('network', 'broken')->exists())->toBeFalse();
});

it('shows a switched-off network as nothing in the UI and a switched-on one as a card', function () {
    $html = Blade::render('<x-social-autopost::connection network="fake" />');
    expect(trim($html))->toBe('');

    $this->network('fake');
    $html = Blade::render('<x-social-autopost::connection network="fake" />');
    expect($html)->toContain('Connect Fake');
});

it('reads a custom network registered through the config', function () {
    config()->set('social-autopost.networks.mastodon', [
        'enabled' => true,
        'autopost' => false,
        'class' => FakeNetwork::class,
    ]);

    expect(Social::network('mastodon'))->toBeInstanceOf(FakeNetwork::class);
    expect(Social::isEnabled('mastodon'))->toBeTrue();
    expect(Social::keys())->toContain('mastodon');
});
