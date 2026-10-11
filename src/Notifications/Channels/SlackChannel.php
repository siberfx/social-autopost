<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Contracts\NotificationChannel;
use Siberfx\SocialAutopost\Exceptions\NotificationFailed;
use Siberfx\SocialAutopost\Notifications\Message;

/** Incoming webhook: https://api.slack.com/messaging/webhooks */
final class SlackChannel implements NotificationChannel
{
    private readonly string $webhookUrl;

    /** @param  array<string, mixed>  $config */
    public function __construct(array $config = [])
    {
        $url = $config['webhook_url'] ?? null;
        $this->webhookUrl = is_string($url) ? trim($url) : '';
    }

    public function configured(): bool
    {
        return str_starts_with($this->webhookUrl, 'https://');
    }

    public function send(Message $message): void
    {
        if (! $this->configured()) {
            throw new NotificationFailed('Slack needs an https webhook_url.');
        }

        $text = '*'.self::escape($message->emoji().' '.$message->headline).'*';

        foreach ($message->lines as $line) {
            $text .= "\n".self::escape($line);
        }

        try {
            $response = Http::timeout((int) config('social-autopost.http.timeout', 20))
                ->post($this->webhookUrl, ['text' => $text, 'unfurl_links' => false]);
        } catch (ConnectionException $e) {
            throw NotificationFailed::redacted('Could not reach Slack: '.$e->getMessage(), $this->webhookUrl);
        }

        if ($response->failed()) {
            throw NotificationFailed::redacted(
                'Slack refused the message: '.(trim($response->body()) ?: 'HTTP '.$response->status()),
                $this->webhookUrl,
            );
        }
    }

    /** Slack mrkdwn treats only &, < and > specially. */
    private static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }
}
