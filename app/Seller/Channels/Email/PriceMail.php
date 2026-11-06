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

    public function __construct(public string $path, public string $filename, public string $senderEmail, public string $senderName, public string $signature = '', public ?string $priceUrl = null, public ?string $catalogCsv = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->senderEmail, $this->senderName), subject: 'Прайс-лист');
    }

    public function content(): Content
    {
        $body = $this->priceUrl !== null
            ? '<p>Здравствуйте! Прайс-лист доступен по ссылке: <a href="'.e($this->priceUrl).'">'.e($this->priceUrl).'</a></p>'
            : '<p>Здравствуйте! Направляем прайс-лист во вложении.</p>';

        return new Content(htmlString: $body.($this->signature !== '' ? '<p>'.nl2br(e($this->signature)).'</p>' : ''));
    }

    public function attachments(): array
    {
        if ($this->priceUrl !== null) {
            return [];
        }
        if ($this->catalogCsv !== null) {
            return [Attachment::fromData(fn () => $this->catalogCsv, $this->filename)->withMime('text/csv')];
        }

        return [Attachment::fromStorageDisk('local', $this->path)->as($this->filename)];
    }
}
