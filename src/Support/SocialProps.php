<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\SocialAutopostServiceProvider;
use Siberfx\SocialAutopost\SocialManager;

/**
 * The connection cards and share buttons as plain arrays, for pages built in
 * React, Vue or anything else: pass them as Inertia props, or fetch them from
 * GET /social/connections. Never carries a token.
 */
final class SocialProps
{
    /**
     * One entry per enabled network, in config order.
     *
     * @return list<array<string, mixed>>
     */
    public static function connections(): array
    {
        $social = app(SocialManager::class);

        return array_values($social->enabled()->map(fn (string $key) => self::connection($key, $social))->all());
    }

    /** @return array<string, mixed> */
    public static function connection(string $network, ?SocialManager $social = null): array
    {
        $social ??= app(SocialManager::class);
        $label = $social->network($network)->label();
        $connection = $social->connection($network);
        $presenter = new ConnectionPresenter($connection, $label);
        $routes = (string) config('social-autopost.routes.name', 'social-autopost.');

        return [
            'network' => $network,
            'label' => $label,
            'brand' => NetworkBrand::for($network),
            'hint' => NetworkNotes::hint($network),
            'autopost' => $social->autopostEnabled($network),
            'connection' => $connection->toArray(),
            'badge' => $presenter->badge(),
            'needs_reconnect' => $connection->connected && $presenter->needsReconnect(),
            'confirm_disconnect' => NetworkNotes::confirmDisconnect($network, $label),
            'can_manage' => Gate::allows(SocialAutopostServiceProvider::ABILITY),
            'urls' => [
                // Open with a full page load (window.location): it leaves for the network's login.
                'connect' => route($routes.'redirect', ['network' => $network]),
                // DELETE with Accept: application/json.
                'disconnect' => route($routes.'connection.destroy', ['network' => $network]),
            ],
        ];
    }

    /**
     * A share button for one model: to one network, or to every enabled and
     * connected network when $network is null. POST to `url` with
     * Accept: application/json; the URL is signed for this model.
     *
     * @return array<string, mixed>
     */
    public static function share(Model&ShareableOnSocial $model, ?string $network = null, ?string $label = null): array
    {
        $presenter = new ShareButtonPresenter($model, app(SocialManager::class), $network, $label);

        return [
            'network' => $network,
            'url' => $presenter->signedAction(),
            'text' => $presenter->text(),
            'title' => $presenter->title(),
            'confirm' => $presenter->confirmText(),
            'disabled_reason' => $presenter->disabledReason(),
            'last_posted_at' => $presenter->lastPostedAt()?->toIso8601String(),
        ];
    }

    /**
     * The message for the page after the OAuth callback sent the browser back
     * with ?social=…&state=…, or a flashed message. Null when there is none.
     *
     * @return array{type: string, message: string}|null
     */
    public static function flash(?Request $request = null): ?array
    {
        $request ??= request();
        $network = $request->query('social');
        $social = app(SocialManager::class);
        $label = is_string($network) && $social->isEnabled($network) ? $social->network($network)->label() : null;

        return StatusMessages::current($request, $label);
    }
}
