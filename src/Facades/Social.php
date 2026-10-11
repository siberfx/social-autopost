<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Contracts\SocialNetwork;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;

/**
 * @method static list<string> keys()
 * @method static SocialNetwork network(string $key)
 * @method static \Illuminate\Support\Collection<int, string> enabled()
 * @method static bool isEnabled(string $key)
 * @method static bool autopostEnabled(string $key)
 * @method static bool isConnected(string $key)
 * @method static Connection connection(string $key)
 * @method static string connect(string $key)
 * @method static Connection completeConnection(string $key, Request $request)
 * @method static bool disconnect(string $key)
 * @method static \Illuminate\Support\Collection<int, SharedPost> share(Model&ShareableOnSocial $model, ?string $network = null, string $trigger = 'manual')
 * @method static SharedPost shareTo(Model&ShareableOnSocial $model, string $network, string $trigger = 'manual')
 * @method static void queue(Model&ShareableOnSocial $model, ?string $network = null, string $trigger = 'manual')
 * @method static bool wasPosted(Model $model, ?string $network = null)
 * @method static \Illuminate\Support\Collection<int, string> targets()
 *
 * @see SocialManager
 */
final class Social extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SocialManager::class;
    }
}
