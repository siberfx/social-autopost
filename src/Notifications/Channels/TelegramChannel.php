<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\NotificationChannel;
use Siberfx\SocialAutopost\Exceptions\NotificationFailed;
use Siberfx\SocialAutopost\Notifications\Message;

/** Bot API sendMessage: https://core.telegram.org/bots/api#sendmessage */
final class TelegramChannel implements NotificationChannel
{
    private readonly string $botToken;

    private readonly string $chatId;

    private readonly string $threadId;

    /** @param  array<string, mixed>  $config */
    public function __construct(array $config = [])
    {
        $this->botToken = self::string($config['bot_token'] ?? null);
        $this->chatId = self::string($config['chat_id'] ?? null);
        $this->threadId = self::string($config['thread_id'] ?? null);
    }

    public function configured(): bool
    {
        return $this->botToken !== '' && $this->chatId !== '';
    }

    public function send(Message $message): void
    {
        if (! $this->configured()) {
            throw new NotificationFailed('Telegram needs a bot_token and a chat_id.');
        }

        $text = '<b>'.self::escape($message->emoji().' '.$message->headline).'</b>';

        foreach ($message->lines as $line) {
            $text .= "\n".self::escape($line);
        }

        try {
            $response = Http::timeout((int) config('social-autopost.http.timeout', 20))
                ->acceptJson()
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", array_filter([
                    'chat_id' => $this->chatId,
                    'message_thread_id' => $this->threadId !== '' ? (int) $this->threadId : null,
                    'text' => mb_substr($text, 0, 4096),
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                ], fn (mixed $value) => $value !== null));
        } catch (ConnectionException $e) {
            throw NotificationFailed::redacted('Could not reach Telegram: '.$e->getMessage(), $this->botToken);
        }

        if ($response->failed() || $response->json('ok') !== true) {
            $reason = $response->json('description');

            throw NotificationFailed::redacted(
                'Telegram refused the message: '.(is_string($reason) ? $reason : 'HTTP '.$response->status()),
                $this->botToken,
            );
        }
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
