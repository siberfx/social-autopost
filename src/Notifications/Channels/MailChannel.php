<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications\Channels;

use Illuminate\Support\Facades\Mail;
use Siberfx\SocialAutopost\Contracts\NotificationChannel;
use Siberfx\SocialAutopost\Exceptions\NotificationFailed;
use Siberfx\SocialAutopost\Notifications\Message;
use Siberfx\SocialAutopost\Notifications\NotificationMail;
use Throwable;

/** Email through your app's own mailer (config/mail.php). */
final class MailChannel implements NotificationChannel
{
    /** @var list<string> */
    private readonly array $to;

    private readonly ?string $mailer;

    /** @param  array<string, mixed>  $config */
    public function __construct(array $config = [])
    {
        $to = $config['to'] ?? [];
        $addresses = is_string($to) ? explode(',', $to) : (is_array($to) ? $to : []);

        $this->to = array_values(array_filter(
            array_map(fn (mixed $address) => is_string($address) ? trim($address) : '', $addresses),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));

        $mailer = $config['mailer'] ?? null;
        $this->mailer = is_string($mailer) && $mailer !== '' ? $mailer : null;
    }

    public function configured(): bool
    {
        return $this->to !== [];
    }

    public function send(Message $message): void
    {
        if (! $this->configured()) {
            throw new NotificationFailed('Mail needs at least one valid address in "to".');
        }

        try {
            Mail::mailer($this->mailer)->to($this->to)->send(new NotificationMail($message));
        } catch (Throwable $e) {
            throw new NotificationFailed('Could not send the email: '.$e->getMessage(), previous: $e);
        }
    }
}
