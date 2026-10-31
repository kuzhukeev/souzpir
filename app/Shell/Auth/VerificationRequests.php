<?php

namespace App\Shell\Auth;

use App\Models\User;
use App\Shell\Auth\VerificationDelivery\DisabledTransport;
use App\Shell\Auth\VerificationDelivery\Jobs\DeliverVerification;
use App\Shell\Auth\VerificationDelivery\SmtpVerificationTransport;
use App\Shell\Auth\VerificationDelivery\VerificationDelivery;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class VerificationRequests
{
    public function available(): bool
    {
        if (! config('features.E1-01D.defaults.enabled', false)) {
            return false;
        }
        $transport = app(VerificationTransport::class);

        if ($transport instanceof DisabledTransport) {
            return false;
        }
        if ($transport instanceof SmtpVerificationTransport) {
            return (bool) config('features.E1-01D.defaults.smtp_enabled', false)
                && config('mail.mailers.smtp.transport') === 'smtp'
                && is_string(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== ''
                && is_string(config('mail.from.address')) && config('mail.from.address') !== '';
        }

        return true;
    }

    public function request(Request $request, string $key): string
    {
        if (! $this->available()) {
            // Recheck the registered session even when delivery is disabled.
            return app(AuthSessionRegistry::class)->withinActive($request,
                static fn (User $user): string => $user->hasVerifiedEmail() ? 'verified' : 'disabled');
        }
        $result = app(VerificationDelivery::class)->enqueue($request, $key);
        if ($result['status'] === 'pending') {
            // The intent is durable even if dispatch fails. A bounded pending-only
            // scanner may safely schedule it later; no uncertain send is retried.
            try {
                $this->dispatch($result['id']);
            } catch (Throwable) {
            }
        }

        return $result['status'];
    }

    public function dispatch(string $intentId): void
    {
        $job = (new DeliverVerification($intentId))->onQueue(config('runtime.queues.notifications'))->afterCommit();
        Bus::dispatch($job);
    }

    public function dispatchPending(int $limit): array
    {
        if (! $this->available() || $limit < 1 || $limit > 500) {
            return ['queued' => 0, 'failed' => 0];
        }
        $ids = DB::table('verification_delivery_intents')->where('status', 'pending')->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
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

    public static function message(string $status): string
    {
        return match ($status) {
            'pending', 'sending', 'transmitting' => 'Запрос на отправку принят. Проверьте почту позже.',
            'sent' => 'Почтовый сервис принял письмо. Проверьте почту.',
            'verified' => 'Почта уже подтверждена.',
            'uncertain' => 'Результат отправки неизвестен. Обратитесь к администратору.',
            'conflict' => 'Запрос уже обрабатывается в другой сессии.',
            'cancelled' => 'Запрос на отправку отменён.',
            'failed' => 'Письмо не отправлено. Можно повторить запрос.',
            default => 'Почтовый сервис пока не подключён.',
        };
    }
}
