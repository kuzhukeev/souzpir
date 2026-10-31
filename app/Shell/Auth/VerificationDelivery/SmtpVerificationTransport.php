<?php

namespace App\Shell\Auth\VerificationDelivery;

use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/** Explicitly opt-in SMTP adapter. Never bind or enable until operator approval. */
final class SmtpVerificationTransport implements VerificationTransport
{
    public function send(User $user): bool
    {
        if (! config('features.E1-01D.defaults.enabled') || ! config('features.E1-01D.defaults.smtp_enabled') || config('mail.mailers.smtp.transport') !== 'smtp' || ! is_string(config('mail.mailers.smtp.host')) || config('mail.mailers.smtp.host') === '' || ! is_string(config('mail.from.address')) || config('mail.from.address') === '') {
            throw new KnownDeliveryFailure('Verification SMTP is disabled or unconfigured.');
        }
        try {
            $minutes = max(1, (int) config('auth.verification.expire', 60));
            $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
            $html = '<p>Подтвердите адрес электронной почты для входа в кабинет.</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">Подтвердить почту</a></p><p>Ссылка действует ограниченное время.</p>';
        } catch (Throwable) {
            throw new KnownDeliveryFailure('Verification message could not be prepared.');
        }
        // Once the transport is called, every exception or missing receipt is ambiguous.
        $receipt = Mail::mailer('smtp')->html($html, function (Message $message) use ($user): void {
            $message->to($user->getEmailForVerification())->subject('Подтверждение электронной почты');
        });

        return $receipt instanceof SentMessage;
    }
}
