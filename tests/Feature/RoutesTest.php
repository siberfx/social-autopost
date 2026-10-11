<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\Tests\Fixtures\FakeNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

it('refuses the connection routes to someone the gate denies', function () {
    Gate::define(SocialAutopostServiceProvider::ABILITY, fn ($user = null) => false);
    $this->network('fake');

    $this->get('/social/fake/redirect')->assertForbidden();
});

it('answers 404 for a switched-off network on every route', function () {
    $this->get('/social/fake/redirect')->assertNotFound();
    $this->get('/social/fake/callback?code=abc')->assertNotFound();
    $this->delete('/social/fake/connection')->assertNotFound();
});

it('answers 404 for a network that is not configured', function () {
    $this->get('/social/myspace/redirect')->assertNotFound();
});

it('sends the browser to the network consent screen when it is switched on', function () {
    $this->network('fake');

    $this->get('/social/fake/redirect')->assertRedirect('https://fake.test/authorize');
});

it('returns to the app with the failure state when the callback has no code', function () {
    $this->network('fake');

    $this->get('/social/fake/callback')->assertRedirect('/?social=fake&state=missing_code');
});

it('stores the connection on a successful callback and returns with connected', function () {
    $this->network('fake');

    $this->get('/social/fake/callback?code=abc')->assertRedirect('/?social=fake&state=connected');
    expect(Social::isConnected('fake'))->toBeTrue();
});

it('forgets the connection on disconnect', function () {
    $this->network('fake');
    $this->connect('fake');

    $this->delete('/social/fake/connection')->assertRedirect();

    expect(Social::isConnected('fake'))->toBeFalse();
});

it('rejects a share request whose signature is missing', function () {
    $this->network('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    $this->post('/social/fake/share?type=post&id='.$post->getKey())->assertForbidden();
});

it('shares to the network named in a signed share request', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    $url = URL::signedRoute('social-autopost.share.network', [
        'network' => 'fake',
        'type' => 'post',
        'id' => $post->getKey(),
    ]);

    $this->postJson($url)
        ->assertCreated()
        ->assertJsonPath('data.0.network', 'fake')
        ->assertJsonPath('data.0.status', SharedPost::STATUS_POSTED);

    expect(FakeNetwork::$published)->toHaveCount(1);
});

it('shares to every connected network in an all-networks signed request', function () {
    $this->network('fake');
    $this->connect('fake');
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    $url = URL::signedRoute('social-autopost.share', ['type' => 'post', 'id' => $post->getKey()]);

    $this->postJson($url)->assertCreated();
});

it('answers 404 for a signed share request to a switched-off network', function () {
    $post = Post::create(['title' => 'Hello', 'status' => 'published']);

    $url = URL::signedRoute('social-autopost.share.network', [
        'network' => 'fake',
        'type' => 'post',
        'id' => $post->getKey(),
    ]);

    $this->postJson($url)->assertNotFound();
});
