<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Facades\Social;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\Support\Columns;
use Siberfx\SocialAutopost\Tests\Fixtures\FailingNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\FakeNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\FakePagesNetwork;
use Siberfx\SocialAutopost\Tests\Fixtures\Post;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Relation::morphMap(['post' => Post::class]);

        FakeNetwork::$published = [];
        FailingNetwork::$status = 503;
        FakePagesNetwork::$failing = [];
        FakePagesNetwork::$postedTo = [];
        Columns::flush();

        // Most tests act as an allowed admin; the deny case overrides this.
        Gate::define(SocialAutopostServiceProvider::ABILITY, fn ($user = null) => true);

        // Set after boot: networks is a nested config array and would otherwise
        // replace the built-in linkedin, x and facebook entries.
        config()->set('social-autopost.networks.fake', [
            'enabled' => false,
            'autopost' => false,
            'class' => FakeNetwork::class,
            'post_url' => 'https://fake.test/posts/{id}',
        ]);
        config()->set('social-autopost.networks.broken', [
            'enabled' => false,
            'autopost' => false,
            'class' => FailingNetwork::class,
        ]);
        config()->set('social-autopost.networks.paged', [
            'enabled' => false,
            'autopost' => false,
            'class' => FakePagesNetwork::class,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            class_exists(LivewireServiceProvider::class) ? LivewireServiceProvider::class : null,
            SocialAutopostServiceProvider::class,
        ]));
    }

    protected function getPackageAliases($app): array
    {
        return ['Social' => Social::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        // The routes are loaded at boot, so the middleware the tests expect is set here.
        $app['config']->set('social-autopost.routes.middleware', ['web', 'can:manage-social-autopost']);
        // Tests run in the CLI, where auto-post is off unless allowed.
        $app['config']->set('social-autopost.autopost.in_console', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /** Switch a configured network on or off for the test. */
    protected function network(string $key, bool $enabled = true, bool $autopost = false): void
    {
        config()->set("social-autopost.networks.{$key}.enabled", $enabled);
        config()->set("social-autopost.networks.{$key}.autopost", $autopost);
    }

    /** Store a connection for the network, as a completed OAuth flow would. */
    protected function connect(string $key): void
    {
        app(ConnectionStore::class)->put($key, ['token' => 'secret-'.$key]);
    }
}
