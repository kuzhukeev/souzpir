<?php

namespace App\Shell\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NotificationMail extends Mailable
{
    public function __construct(public readonly string $title) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->title).'</p>');
    }
}
