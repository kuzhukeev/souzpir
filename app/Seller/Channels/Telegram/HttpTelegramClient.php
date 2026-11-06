<?php

namespace App\Seller\Channels\Telegram;

use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpTelegramClient
{
    public function bot(#[\SensitiveParameter] string $token): ?array
    {
        if (! preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $token)) {
            return null;
        }
        try {
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->get('https://api.telegram.org/bot'.$token.'/getMe');
            $result = $response->json('result');
            if (! $response->successful() || $response->json('ok') !== true || ! is_array($result)
                || ($result['is_bot'] ?? false) !== true || ! is_string($result['first_name'] ?? null)
                || ! is_string($result['username'] ?? null) || ! preg_match('/^[A-Za-z0-9_]{1,32}$/D', $result['username'])) {
                return null;
            }

            return ['name' => $result['first_name'], 'username' => $result['username']];
        } catch (Throwable) {
            return null;
        }
    }
}
