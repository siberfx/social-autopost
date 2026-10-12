<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Support\NetworkNotes;
use Siberfx\SocialAutopost\Support\SocialProps;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

// The tests rewrite the same view within a second; Blade would serve the older compiled copy.
beforeEach(fn () => $this->artisan('view:clear'));

afterEach(function () {
    File::deleteDirectory(resource_path('views/social'));
    File::deleteDirectory(resource_path('views/admin'));
});

function cardFor(string $network, string $theme = 'tailwind'): string
{
    config()->set('social-autopost.ui.theme', $theme);

    return Blade::render('<x-social-autopost::connection network="'.$network.'" />');
}

it('shows the Instagram and TikTok setup hints on their cards in both themes', function (string $theme) {
    $this->network('instagram');
    $this->network('tiktok');

    expect(cardFor('instagram', $theme))->toContain('Posts need an image or a video')
        ->and(cardFor('tiktok', $theme))->toContain('it only accepts private posts');
})->with(['tailwind', 'bootstrap']);

it('shows no hint for a network that needs none', function () {
    $this->network('fake');

    expect(NetworkNotes::hint('fake'))->toBeNull()
        ->and(NetworkNotes::hint('linkedin'))->toBeNull();
});

it('tells TikTok users their posts are private once SELF_ONLY is set', function () {
    config()->set('social-autopost.networks.tiktok.privacy_level', 'SELF_ONLY');

    expect(NetworkNotes::hint('tiktok'))->toContain('posted as private');
});

it('lets the config replace or hide a hint', function () {
    config()->set('social-autopost.networks.instagram.hint', 'Use the brand account.');
    config()->set('social-autopost.networks.tiktok.hint', '');
    config()->set('social-autopost.networks.fake.hint', 'Only for testing.');

    expect(NetworkNotes::hint('instagram'))->toBe('Use the brand account.')
        ->and(NetworkNotes::hint('tiktok'))->toBeNull()
        ->and(NetworkNotes::hint('fake'))->toBe('Only for testing.');
});

it('does not promise a revocation Instagram cannot make', function () {
    $this->network('instagram');
    app(ConnectionStore::class)->put('instagram', ['access_token' => 't', 'user_id' => '1', 'expires_at' => now()->addDays(50)->toIso8601String()]);
    Http::fake(['https://graph.instagram.com/*' => Http::response(['user_id' => '1', 'username' => 'siberfx'])]);

    expect(NetworkNotes::confirmDisconnect('instagram', 'Instagram'))->not->toContain('is revoked')->toContain('Apps and websites')
        ->and(NetworkNotes::confirmDisconnect('linkedin', 'LinkedIn'))->toContain('The connection is revoked')
        ->and(cardFor('instagram'))->toContain('Apps and websites');
});

it('explains where to remove Instagram\'s access after disconnecting', function () {
    $this->network('instagram');
    app(ConnectionStore::class)->put('instagram', ['access_token' => 't', 'user_id' => '1']);

    $this->deleteJson('/social/instagram/connection')
        ->assertOk()
        ->assertJson(['revoked' => false, 'message' => 'Instagram disconnected here. To remove its access completely, remove the app under Instagram → Settings → Apps and websites.']);

    expect(NetworkNotes::disconnected('fake', 'Fake', false))->toContain('did not confirm revoking')
        ->and(NetworkNotes::disconnected('instagram', 'Instagram', true))->toBe('Instagram disconnected.');
});

it('writes a standalone connections page that shows every enabled network', function () {
    $this->network('instagram');
    $this->network('tiktok');

    $this->artisan('social:page', ['--theme' => 'tailwind'])
        ->expectsOutputToContain("Route::view('/admin/social', 'social.connections')")
        ->assertSuccessful();

    $path = resource_path('views/social/connections.blade.php');
    $source = File::get($path);

    expect($source)->toStartWith('<!doctype html>')
        ->toContain('@tailwindcss/browser')
        ->toContain('<x-social-autopost::connection :network="$network" />');

    $html = view('social.connections')->render();

    expect($html)->toContain('Connect Instagram')
        ->toContain('Connect TikTok')
        ->toContain(url('/social/tiktok/callback'))
        ->not->toContain('Connect LinkedIn');
});

it('shows how to switch networks on when none is', function () {
    $this->artisan('social:page', ['--theme' => 'bootstrap'])->assertSuccessful();

    expect(view('social.connections')->render())->toContain('No network is switched on')
        ->toContain('bootstrap.min.css');
});

it('writes the page into a layout, with Livewire cards, at another view name', function () {
    $this->artisan('social:page', [
        '--theme' => 'bootstrap',
        '--layout' => 'layouts.admin',
        '--section' => 'main',
        '--livewire' => true,
        '--view' => 'admin.social',
    ])->assertSuccessful();

    $source = File::get(resource_path('views/admin/social.blade.php'));

    expect($source)->toStartWith("@extends('layouts.admin')\n\n@section('main')")
        ->toContain('<livewire:social-autopost.connection :network="$network"')
        ->toContain('class="col-md-6"')
        ->not->toContain('<!doctype html>');
});

it('refuses to overwrite an existing page unless forced', function () {
    $this->artisan('social:page')->assertSuccessful();
    File::put(resource_path('views/social/connections.blade.php'), 'mine');

    $this->artisan('social:page')->assertFailed();
    expect(File::get(resource_path('views/social/connections.blade.php')))->toBe('mine');

    $this->artisan('social:page', ['--force' => true])->assertSuccessful();
    expect(File::get(resource_path('views/social/connections.blade.php')))->not->toBe('mine');
});

it('refuses an unknown theme and headless mode', function () {
    $this->artisan('social:page', ['--theme' => 'bulma'])->assertFailed();

    config()->set('social-autopost.ui.enabled', false);
    $this->artisan('social:page')->assertFailed();
});

it('lists every enabled network as JSON for React or Vue pages, without tokens', function () {
    $this->network('instagram');
    $this->network('tiktok', autopost: true);
    app(ConnectionStore::class)->put('instagram', ['access_token' => 'secret-token', 'user_id' => '1', 'username' => 'siberfx']);
    Http::fake(['https://graph.instagram.com/*' => Http::response(['user_id' => '1', 'username' => 'siberfx'])]);

    $response = $this->getJson('/social/connections')->assertOk();

    expect(collect($response->json('data'))->pluck('network')->all())->toBe(['instagram', 'tiktok'])
        ->and($response->getContent())->not->toContain('secret-token');

    $response->assertJsonPath('data.0.label', 'Instagram')
        ->assertJsonPath('data.0.connection.connected', true)
        ->assertJsonPath('data.0.connection.name', 'siberfx')
        ->assertJsonPath('data.0.badge.tone', 'success')
        ->assertJsonPath('data.0.can_manage', true)
        ->assertJsonPath('data.0.urls.connect', url('/social/instagram/redirect'))
        ->assertJsonPath('data.0.urls.disconnect', url('/social/instagram/connection'))
        ->assertJsonPath('data.1.connection.connected', false)
        ->assertJsonPath('data.1.autopost', true)
        ->assertJsonPath('data.1.brand.dark', true)
        ->assertJsonPath('flash', null);

    expect($response->json('data.0.hint'))->toContain('image or a video')
        ->and($response->json('data.0.confirm_disconnect'))->toContain('Apps and websites');
});

it('turns the callback query into the message for the page', function () {
    $this->network('tiktok');

    $this->getJson('/social/connections?social=tiktok&state=connected')
        ->assertJsonPath('flash', ['type' => 'success', 'message' => 'TikTok connected.']);
});

it('refuses the JSON list to someone the gate denies', function () {
    Gate::define('manage-social-autopost', fn ($user = null) => false);

    $this->getJson('/social/connections')->assertForbidden();
});

it('gives a front end a signed share button that shares the model', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::query()->create(['title' => 'Hello', 'status' => 'published']);

    $share = SocialProps::share($post, 'fake');

    expect($share['url'])->toContain('/social/fake/share')->toContain('signature=')
        ->and($share['text'])->toBe('Share on Fake')
        ->and($share['disabled_reason'])->toBeNull()
        ->and($share['last_posted_at'])->toBeNull();

    $this->postJson($share['url'])->assertCreated();

    expect(SocialProps::share($post->fresh(), 'fake')['text'])->toBe('Share again on Fake');
});
