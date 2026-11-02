<?php

namespace App\Seller\Channels\Telegram;

final class DisabledTelegramTransport implements TelegramTransport
{
    public function register(#[\SensitiveParameter] string $token, string $url, #[\SensitiveParameter] string $secret): bool
    {
        return false;
    }

    public function send(#[\SensitiveParameter] string $token, string $chatId, #[\SensitiveParameter] string $text): bool
    {
        return false;
    }
}
