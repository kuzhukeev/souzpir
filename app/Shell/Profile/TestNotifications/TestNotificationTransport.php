<?php

namespace App\Shell\Profile\TestNotifications;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use LogicException;

class TestNotificationTransport
{
    public function available(string $channel): bool
    {
        if ($channel === 'cabinet') {
            return true;
        }
        if (! config('features.E0-04.defaults.external_delivery', false)) {
            return false;
        }

        return match ($channel) {
            'email' => config('mail.default') === 'smtp' && is_string(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== '',
            'telegram' => is_string(config('features.E0-04.defaults.telegram_bot_token')) && config('features.E0-04.defaults.telegram_bot_token') !== '',
            default => false,
        };
    }

    public function send(string $channel, #[\SensitiveParameter] string $destination): bool
    {
        if (! $this->available($channel)) {
            throw new LogicException('Test transport is unavailable.');
        }
        $title = 'Тестовое уведомление личного кабинета';
        if ($channel === 'email') {
            return Mail::raw($title, fn ($message) => $message->to($destination)->subject($title)) instanceof SentMessage;
        }
        if ($channel === 'telegram') {
            $response = Http::asForm()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(5)
                ->post('https://api.telegram.org/bot'.config('features.E0-04.defaults.telegram_bot_token').'/sendMessage', ['chat_id' => $destination, 'text' => $title]);

            return $response->successful() && $response->json('ok') === true;
        }
        throw new LogicException('Unknown external test channel.');
    }
}
