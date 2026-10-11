<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The email the mail channel sends. Needs no views, so it works in headless mode too. */
final class NotificationMail extends Mailable
{
    public function __construct(public readonly Message $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->emoji().' '.$this->notification->headline);
    }

    public function content(): Content
    {
        $html = '<p><strong>'.e($this->notification->headline).'</strong></p>';

        foreach ($this->notification->lines as $line) {
            $html .= '<p>'.(filter_var($line, FILTER_VALIDATE_URL) !== false
                ? '<a href="'.e($line).'">'.e($line).'</a>'
                : e($line)).'</p>';
        }

        return new Content(htmlString: $html);
    }
}
