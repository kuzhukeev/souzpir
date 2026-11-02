<?php

use App\Seller\Channels\Telegram\DisabledTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;

return ['bindings' => [TelegramTransport::class => DisabledTelegramTransport::class]];
