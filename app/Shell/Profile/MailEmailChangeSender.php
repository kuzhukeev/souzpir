<?php

namespace App\Shell\Profile;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;

final class MailEmailChangeSender implements EmailChangeSender
{
    public function send(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $confirmationUrl): bool
    {
        if (! config('features.E1-08.defaults.email_change_enabled')) {
            return false;
        }
        $result = Mail::html('<p>Подтвердите новый адрес почты кабинета:</p><p><a href="'.e($confirmationUrl).'">Подтвердить адрес</a></p><p>Ссылка действует 60 минут. Если вы не меняли почту, не переходите по ней.</p>', function ($message) use ($email): void {
            $message->to($email)->subject('Подтверждение нового адреса почты');
        });

        return $result instanceof SentMessage;
    }
}
