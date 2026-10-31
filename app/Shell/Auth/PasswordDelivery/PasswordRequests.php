<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Shell\Auth\PasswordDelivery\Jobs\DeliverPassword;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PasswordRequests
{
    public function available(): bool
    {
        if (! config('features.E1-01F.defaults.enabled', false)) {
            return false;
        }
        $transport = app(PasswordTransport::class);
        if ($transport instanceof DisabledTransport) {
            return false;
        }
        if ($transport instanceof SmtpPasswordTransport) {
            return (bool) config('features.E1-01F.defaults.smtp_enabled') && config('mail.mailers.smtp.transport') === 'smtp' && is_string(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== '' && is_string(config('mail.from.address')) && config('mail.from.address') !== '';
        }

        return true;
    }

    public function request(#[\SensitiveParameter] string $email): void
    {
        if (! $this->available()) {
            return;
        }
        $id = app(PasswordDelivery::class)->enqueue($email);
        // Never perform transport inline in the public request on a sync queue.
        if ($id && config('queue.default') !== 'sync') {
            try {
                $this->dispatch($id);
            } catch (Throwable) { /* Durable pending intent remains for the scanner. */
            }
        }
    }

    public function dispatch(string $id): void
    {
        Bus::dispatch((new DeliverPassword($id))->onQueue(config('runtime.queues.notifications'))->afterCommit());
    }

    public function dispatchPending(int $limit): array
    {
        if (! $this->available() || $limit < 1 || $limit > 500) {
            return ['queued' => 0, 'failed' => 0];
        }
        $ids = DB::table('password_delivery_intents')->where('status', 'pending')->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $queued = $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->dispatch($id);
                $queued++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return compact('queued', 'failed');
    }
}
