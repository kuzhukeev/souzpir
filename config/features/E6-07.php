<?php

use App\Seller\Settings\Limits\DigestCommand;
use App\Seller\Settings\Limits\DigestSchedule;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Seller\Settings\Limits\SellerUsage;

return [
    'extensions' => ['usage.seller_limits' => SellerUsage::class, 'notifications.router' => SellerNotificationRouter::class],
    'commands' => [DigestCommand::class],
    'schedules' => [DigestSchedule::class],
    'defaults' => ['digest_enabled' => (bool) env('SELLER_DIGEST_ENABLED', false)],
];
