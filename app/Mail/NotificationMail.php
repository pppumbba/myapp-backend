<?php

namespace App\Mail;

use App\Models\UserNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * A notification sent by email: its title as the subject, its message, and a button to what
 * it links to (an app path is opened in the frontend).
 */
class NotificationMail extends Mailable
{
    public function __construct(public UserNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notification', with: [
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'url' => self::url($this->notification->link),
        ]);
    }

    /** The full address of a link: an app path like /run/12 is put on the frontend's address. */
    public static function url(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        if (Str::startsWith($link, ['http://', 'https://'])) {
            return $link;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($link, '/');
    }
}
