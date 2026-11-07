<?php

namespace App\Shell\Team;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

final class InvitationDelivery
{
    /** Synchronous after-commit delivery. No queue, outbox, log mailer or raw-secret persistence. */
    public function deliver(#[\SensitiveParameter] array $issued): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Invitation delivery requires a committed transaction.');
        }
        $invitation = $issued['invitation'];
        $result = ['id' => $invitation->id, 'status' => 'pending', 'expires_at' => $invitation->expires_at->toIso8601String()];
        $mailer = config('features.E1-06.defaults.mailer', 'smtp');
        $enabled = config('features.E1-06.defaults.mail_enabled') === true
            && config('features.E1-06.defaults.smtp_enabled') === true
            && is_string($mailer) && config('mail.mailers.'.$mailer.'.transport') === 'smtp';
        if ($enabled) {
            try {
                $receipt = Mail::mailer($mailer)->to($invitation->email)->send(new InvitationMail($issued['url']));
                if (! $receipt instanceof SentMessage) {
                    return [...$result, 'delivery' => 'fallback', 'delivery_status' => 'uncertain', 'invitation_url' => $issued['url'], 'message' => __('Shell/Team/messages.mail_unconfirmed')];
                }

                return [...$result, 'delivery' => 'mail', 'delivery_status' => 'sent'];
            } catch (Throwable) {
                // SMTP failures may contain message bodies; do not report/log the exception.
                return [...$result, 'delivery' => 'fallback', 'delivery_status' => 'uncertain', 'invitation_url' => $issued['url'], 'message' => __('Shell/Team/messages.mail_unconfirmed')];
            }
        }

        return [...$result, 'delivery' => 'fallback', 'delivery_status' => 'disabled', 'invitation_url' => $issued['url']];
    }
}
