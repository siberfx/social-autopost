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
 * @method static SharedPost shareToPage(Model&ShareableOnSocial $model, string $network, string $page, string $trigger = 'manual')
 * @method static \Illuminate\Support\Collection<int, SharedPost> shareToPages(Model&ShareableOnSocial $model, string $network, ?array<int, string> $pages = null, string $trigger = 'manual')
 * @method static bool hasPages(string $key)
 * @method static list<\Siberfx\SocialAutopost\Data\Page> pages(string $key)
 * @method static list<string> selectedPages(string $key)
 * @method static Connection selectPages(string $key, array<int, string> $ids)
 * @method static void queue(Model&ShareableOnSocial $model, ?string $network = null, string $trigger = 'manual')
 * @method static void shareAt(Model&ShareableOnSocial $model, \DateTimeInterface $when, ?string $network = null)
 * @method static bool wasPosted(Model $model, ?string $network = null, ?string $page = null)
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
