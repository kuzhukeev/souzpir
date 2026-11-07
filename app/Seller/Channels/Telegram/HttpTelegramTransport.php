<?php

namespace App\Seller\Channels\Telegram;

use Illuminate\Support\Facades\Http;

final class HttpTelegramTransport implements TelegramTransport
{
    private function call(#[\SensitiveParameter] string $token, string $method, #[\SensitiveParameter] array $payload): bool
    {
        if (! preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $token)) {
            return false;
        }
        try {
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);

            return $response->successful() && $response->json('ok') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function register(#[\SensitiveParameter] string $token, string $url, #[\SensitiveParameter] string $secret): bool
    {
        return $this->call($token, 'setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'my_chat_member']]);
    }

    public function send(#[\SensitiveParameter] string $token, string $chatId, #[\SensitiveParameter] string $text): bool
    {
        return $this->call($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }
}
