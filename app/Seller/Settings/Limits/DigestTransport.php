<?php

namespace App\Seller\Settings\Limits;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class DigestTransport
{
    /** Any exception after this method starts is treated as an uncertain delivery. */
    public function send(string $channel, string $destination, array $titles): void
    {
        if ($channel === 'email') {
            $receipt = Mail::to($destination)->send(new DigestMail($titles));
            if (! $receipt instanceof SentMessage) {
                throw new RuntimeException('Unconfirmed mail delivery.');
            }

            return;
        }
        if ($channel !== 'telegram') {
            throw new RuntimeException('Invalid digest transport.');
        }
        $token = config('features.E0-04.defaults.telegram_bot_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram is not configured.');
        }
        $heading = $titles === ['Тест уведомлений продавца'] ? 'Тест уведомлений продавца' : 'Сводка уведомлений продавца';
        $response = Http::asForm()->timeout(5)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
            'chat_id' => $destination,
            'text' => $heading."\n".implode("\n", array_map(fn ($title) => '• '.$title, $titles)),
        ])->throw();
        if ($response->json('ok') !== true) {
            throw new RuntimeException('Unconfirmed Telegram delivery.');
        }
    }
}
