<?php

namespace App\Seller\Settings\Limits;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DigestMail extends Mailable
{
    public function __construct(public readonly array $titles) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading());
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->heading()).'</p><ul>'.implode('', array_map(fn ($title) => '<li>'.e($title).'</li>', $this->titles)).'</ul>');
    }

    private function heading(): string
    {
        return $this->titles === ['Тест уведомлений продавца'] ? 'Тест уведомлений продавца' : 'Сводка уведомлений продавца';
    }
}
