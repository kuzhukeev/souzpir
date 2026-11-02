<?php

namespace App\Seller\Channels\Email;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PriceMail extends Mailable
{
    use Queueable;

    public function __construct(public string $path, public string $filename, public string $senderEmail, public string $senderName) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->senderEmail, $this->senderName), subject: 'Прайс-лист');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Здравствуйте! Направляем прайс-лист во вложении.</p>');
    }

    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->path)->as($this->filename)];
    }
}
