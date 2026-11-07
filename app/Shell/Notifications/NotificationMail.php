<?php

namespace App\Shell\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NotificationMail extends Mailable
{
    /** @param list<string> $lines digest items; empty for a single event */
    public function __construct(public readonly string $title, public readonly array $lines = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        $items = $this->lines === [] ? '' : '<ul>'.implode('', array_map(fn (string $line): string => '<li>'.e($line).'</li>', $this->lines)).'</ul>';

        return new Content(htmlString: '<p>'.e($this->title).'</p>'.$items);
    }
}
