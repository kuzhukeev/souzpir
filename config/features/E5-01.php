<?php

use App\Seller\Channels\Telegram\DisabledTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;

return ['defaults' => ['manager_telegram_delivery' => false], 'bindings' => [TelegramTransport::class => DisabledTelegramTransport::class]];
