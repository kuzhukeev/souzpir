<?php

use App\Seller\Notifications\DrainNotificationsSchedule;
use App\Seller\Notifications\QueueInboundNotification;

return [
    'listeners' => ['eloquent.created: App\Seller\Conversations\Models\Message' => [QueueInboundNotification::class]],
    'schedules' => [DrainNotificationsSchedule::class],
    'defaults' => ['dedup_minutes' => (int) env('SELLER_NOTIFICATION_DEDUP_MINUTES', 5)],
];
