<?php

use App\Seller\Notifications\DrainNotificationsSchedule;
use App\Seller\Notifications\QueueInboundNotification;

return [
    'listeners' => ['eloquent.created: App\Seller\Conversations\Models\Message' => [QueueInboundNotification::class]],
    'schedules' => [DrainNotificationsSchedule::class],
    'defaults' => [
        'enabled' => filter_var(env('SELLER_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'dedup_minutes' => (int) env('SELLER_NOTIFICATION_DEDUP_MINUTES', 5),
    ],
];
