<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/** Turns the ?social=…&state=… callback query and the flash message into one message for the page. */
final class StatusMessages
{
    public const FLASH_KEY = 'social-autopost.flash';

    /** @return array{type: string, message: string}|null */
    public static function fromCallback(string $label, ?string $state, ?string $reason = null): ?array
    {
        return match ($state) {
            'connected' => ['type' => 'success', 'message' => Lines::get('connected', ['label' => $label])],
            'cancelled' => ['type' => 'error', 'message' => Lines::get('connection_cancelled', ['label' => $label])],
            'error' => ['type' => 'error', 'message' => Lines::get('connection_refused', [
                'label' => $label,
                'reason' => $reason !== null && $reason !== '' ? $reason : Lines::get('unknown_error'),
            ])],
            'exchange_failed' => ['type' => 'error', 'message' => Lines::get('exchange_failed', ['label' => $label])],
            'invalid_state' => ['type' => 'error', 'message' => Lines::get('login_expired', ['label' => $label])],
            'missing_code' => ['type' => 'error', 'message' => Lines::get('missing_code', ['label' => $label])],
            'not_configured' => ['type' => 'error', 'message' => Lines::get('not_configured', ['label' => $label])],
            default => null,
        };
    }

    /** @return array{type: string, message: string}|null */
    public static function current(Request $request, ?string $label = null): ?array
    {
        $flash = self::session($request)?->get(self::FLASH_KEY);

        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
        }

        $network = $request->query('social');
        $state = $request->query('state');
        $reason = $request->query('reason');

        if (! is_string($network) || $network === '' || ! is_string($state)) {
            return null;
        }

        return self::fromCallback($label ?? ucfirst($network), $state, is_string($reason) ? $reason : null);
    }

    private static function session(Request $request): ?Session
    {
        if ($request->hasSession()) {
            return $request->session();
        }

        return app()->bound('session.store') ? app('session.store') : null;
    }
}
