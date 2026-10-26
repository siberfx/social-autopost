<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

/**
 * What people should know about a network before connecting or disconnecting
 * it, shown on its connection card and in the disconnect messages.
 *
 * Each has a built-in text for the networks that need one, and can be set
 * per network in the config: networks.{key}.hint and networks.{key}.disconnect_note
 * (an empty string hides the built-in text).
 */
final class NetworkNotes
{
    /** A short line for the connection card, or null when there is nothing to say. */
    public static function hint(string $key): ?string
    {
        $hint = self::configured($key, 'hint') ?? match ($key) {
            'instagram' => Lines::get('instagram_hint'),
            'tiktok' => config("social-autopost.networks.{$key}.privacy_level") === 'SELF_ONLY'
                ? Lines::get('tiktok_hint_private')
                : Lines::get('tiktok_hint_audit'),
            default => null,
        };

        return $hint === '' ? null : $hint;
    }

    /** What disconnecting means for this network, or null when the network revokes the access itself. */
    public static function disconnectNote(string $key): ?string
    {
        // Instagram has no API to revoke access: the account owner removes the app instead.
        $note = self::configured($key, 'disconnect_note')
            ?? ($key === 'instagram' ? Lines::get('instagram_disconnect_note') : null);

        return $note === '' ? null : $note;
    }

    /** The question asked before disconnecting. */
    public static function confirmDisconnect(string $key, string $label): string
    {
        $note = self::disconnectNote($key);

        return $note === null
            ? Lines::get('confirm_disconnect', ['label' => $label])
            : Lines::get('confirm_disconnect_noted', ['label' => $label, 'note' => $note]);
    }

    /** The message after disconnecting, given whether the network confirmed the revocation. */
    public static function disconnected(string $key, string $label, bool $revoked): string
    {
        $note = self::disconnectNote($key);

        return match (true) {
            $revoked => Lines::get('disconnected', ['label' => $label]),
            $note !== null => Lines::get('disconnected_noted', ['label' => $label, 'note' => $note]),
            default => Lines::get('disconnected_unconfirmed', ['label' => $label]),
        };
    }

    /** The configured text; null when not configured, so the built-in one applies. An empty string hides the text. */
    private static function configured(string $key, string $name): ?string
    {
        $networks = config('social-autopost.networks');
        $network = is_array($networks) && is_array($networks[$key] ?? null) ? $networks[$key] : [];

        if (! array_key_exists($name, $network)) {
            return null;
        }

        return is_string($network[$name]) ? $network[$name] : '';
    }
}
