<?php

use App\Seller\Channels\Telegram\DisabledTelegramTransport;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;

$liveEnabled = (bool) env('TELEGRAM_LIVE_ENABLED', false);

return [
    'defaults' => ['manager_telegram_delivery' => (bool) env('TELEGRAM_MANAGER_DELIVERY_ENABLED', $liveEnabled)],
    'bindings' => [TelegramTransport::class => $liveEnabled ? HttpTelegramTransport::class : DisabledTelegramTransport::class],
];
