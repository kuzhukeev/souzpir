<?php

namespace App\Shell\Support;

use Illuminate\Mail\Mailable;

final class TicketMail extends Mailable
{
    public function __construct(
        private string $ticketId,
        private string $topic,
        private string $module,
        #[\SensitiveParameter] private string $description,
        #[\SensitiveParameter] private string $authorEmail,
    ) {}

    public function build(): static
    {
        $rows = ['mail_reference' => $this->ticketId, 'mail_topic' => $this->topic, 'mail_module' => $this->module, 'mail_author' => $this->authorEmail, 'mail_body' => $this->description];
        $html = '';
        foreach ($rows as $label => $value) {
            $html .= '<p><strong>'.e(__('Shell/Support/messages.'.$label)).':</strong><br>'.nl2br(e($value)).'</p>';
        }

        return $this->subject(__('Shell/Support/messages.mail_subject'))->replyTo($this->authorEmail)
            ->html($html.'<p>'.e(__('Shell/Support/messages.mail_attachments')).'</p>');
    }
}
