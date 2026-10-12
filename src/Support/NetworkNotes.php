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
    /** Networks with no API to revoke access: where the account owner removes the app instead. */
    private const DISCONNECT_NOTES = [
        'instagram' => 'To remove its access completely, remove the app under Instagram → Settings → Apps and websites.',
    ];

    /** A short line for the connection card, or null when there is nothing to say. */
    public static function hint(string $key): ?string
    {
        $hint = self::configured($key, 'hint') ?? match ($key) {
            'instagram' => 'Posts need an image or a video. Connect an Instagram Business or Creator account.',
            'tiktok' => config("social-autopost.networks.{$key}.privacy_level") === 'SELF_ONLY'
                ? 'Posts need a video or an image, and are posted as private (only you can see them).'
                : 'Posts need a video or an image. Until TikTok audits your app, it only accepts private posts: set TIKTOK_PRIVACY_LEVEL=SELF_ONLY meanwhile.',
            default => null,
        };

        return $hint === '' ? null : $hint;
    }

    /** What disconnecting means for this network, or null when the network revokes the access itself. */
    public static function disconnectNote(string $key): ?string
    {
        $note = self::configured($key, 'disconnect_note') ?? self::DISCONNECT_NOTES[$key] ?? null;

        return $note === '' ? null : $note;
    }

    /** The question asked before disconnecting. */
    public static function confirmDisconnect(string $key, string $label): string
    {
        $note = self::disconnectNote($key);

        return $note === null
            ? "Disconnect {$label}? The connection is revoked and nothing posts there until you connect again."
            : "Disconnect {$label}? It is forgotten here and nothing posts there until you connect again. {$note}";
    }

    /** The message after disconnecting, given whether the network confirmed the revocation. */
    public static function disconnected(string $key, string $label, bool $revoked): string
    {
        $note = self::disconnectNote($key);

        return match (true) {
            $revoked => "{$label} disconnected.",
            $note !== null => "{$label} disconnected here. {$note}",
            default => "{$label} disconnected here, but {$label} did not confirm revoking the connection.",
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
