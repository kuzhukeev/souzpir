<?php

namespace App\Seller\Channels\Telegram;

final class FakeTelegramClient
{
    public function bot(#[\SensitiveParameter] string $token): array
    {
        $id = explode(':', $token, 2)[0];

        return ['name' => 'Тестовый бот', 'username' => 'demo_'.substr($id, -12).'_bot'];
    }
}
