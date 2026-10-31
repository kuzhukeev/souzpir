<?php

use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Audit\DatabaseAuditWriter;
use App\Shell\Notifications\Console\DrainNotifications;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\OutboxDispatcher;

return [
    'commands' => [DrainNotifications::class],
    'defaults' => ['external_delivery' => (bool) env('NOTIFICATIONS_EXTERNAL_ENABLED', false), 'telegram_bot_token' => env('NOTIFICATIONS_TELEGRAM_BOT_TOKEN')],
    'bindings' => [AuditWriter::class => DatabaseAuditWriter::class, NotificationDispatcher::class => OutboxDispatcher::class],
];
