<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Contracts\SocialNetwork;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/** An in-memory network: publishes succeed and are recorded in $published. */
class FakeNetwork implements SocialNetwork
{
    /** @var list<array{network: string, url: string, title: string, description: string, commentary: string}> */
    public static array $published = [];

    public function __construct(private readonly ConnectionStore $store) {}

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function isConnected(): bool
    {
        return $this->store->get($this->key()) !== null;
    }

    public function connection(): Connection
    {
        return $this->isConnected()
            ? new Connection(network: $this->key(), connected: true, status: 'active', name: 'Fake account')
            : Connection::disconnected($this->key());
    }

    public function beginConnection(): string
    {
        return 'https://fake.test/authorize';
    }

    public function completeConnection(Request $request): Connection
    {
        if (! $request->filled('code')) {
            throw new ConnectionFailed('missing_code', 'Fake did not return an authorization code.');
        }

        $this->store->put($this->key(), ['token' => 'secret-'.$request->query('code')]);

        return $this->connection();
    }

    public function disconnect(): bool
    {
        $this->store->forget($this->key());

        return true;
    }

    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        self::$published[] = [
            'network' => $this->key(),
            'url' => $post->url(),
            'title' => $post->title(),
            'description' => $post->descriptionText(),
            'commentary' => $post->commentaryText(),
        ];

        return 'fake-'.count(self::$published);
    }
}
