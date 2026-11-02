<?php

namespace App\Seller\Channels\Telegram;

interface TelegramTransport
{
    public function register(#[\SensitiveParameter] string $token, string $url, #[\SensitiveParameter] string $secret): bool;

    public function send(#[\SensitiveParameter] string $token, string $chatId, #[\SensitiveParameter] string $text): bool;
}
