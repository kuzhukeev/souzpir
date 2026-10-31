<?php

namespace App\Shell\Support;

use App\Models\User;
use App\Shell\Support\Models\Ticket;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

final class TicketDelivery
{
    public function configuration(): array
    {
        $recipient = config('features.E1-12.defaults.recipient');
        $mailer = config('features.E1-12.defaults.mailer');

        return ['configured' => is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false,
            'enabled' => config('features.E1-12.defaults.mail_enabled') === true && config('features.E1-12.defaults.smtp_enabled') === true
                && is_string($mailer) && config('mail.mailers.'.$mailer.'.transport') === 'smtp'];
    }

    public function send(Ticket $ticket): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Support mail requires the outermost business commit.');
        }
        $state = $this->configuration();
        $status = ! $state['configured'] ? 'not_configured' : (! $state['enabled'] ? 'disabled' : 'sending');
        // No automatic retry: an interrupted or uncertain transmission must not be sent twice.
        $changed = Ticket::query()->whereKey($ticket->id)->where('user_id', $ticket->user_id)->where('delivery_status', 'pending')->update(['delivery_status' => $status, 'updated_at' => now()]);
        if ($changed !== 1) {
            $status = $ticket->fresh()->delivery_status;

            return $this->result($status);
        }
        if ($status === 'sending') {
            try {
                $email = User::query()->whereKey($ticket->user_id)->value('email');
                $receipt = Mail::mailer(config('features.E1-12.defaults.mailer'))->to(config('features.E1-12.defaults.recipient'))
                    ->send(new TicketMail($ticket->id, __('Shell/Support/messages.topics.'.$ticket->subject), __('Shell/Support/messages.modules.'.$ticket->module), $ticket->body, $email));
                $status = $receipt instanceof SentMessage ? 'sent' : 'uncertain';
            } catch (Throwable) {
                // Transport exceptions may contain private ticket text or credentials; do not report/log them.
                $status = 'uncertain';
            }
            Ticket::query()->whereKey($ticket->id)->where('user_id', $ticket->user_id)->where('delivery_status', 'sending')->update(['delivery_status' => $status, 'updated_at' => now()]);
        }

        return $this->result($status);
    }

    public function existing(Ticket $ticket): array
    {
        return $this->result($ticket->fresh()->delivery_status);
    }

    private function result(string $status): array
    {
        if (in_array($status, ['pending', 'sending'], true)) {
            $status = 'uncertain';
        }

        return ['status' => $status, 'message' => __('Shell/Support/messages.delivery_'.$status)];
    }
}
