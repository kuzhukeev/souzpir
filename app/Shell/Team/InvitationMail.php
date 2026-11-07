<?php

namespace App\Shell\Team;

use Illuminate\Mail\Mailable;

final class InvitationMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] private string $invitationUrl) {}

    public function build(): self
    {
        return $this->subject(__('Shell/Team/messages.mail_subject'))->html(
            '<p>'.e(__('Shell/Team/messages.mail_intro')).'</p><p><a href="'.e($this->invitationUrl).'">'.e(__('Shell/Team/messages.mail_button')).'</a></p><p>'.e(__('Shell/Team/messages.mail_expiry')).'</p>'
        );
    }
}
