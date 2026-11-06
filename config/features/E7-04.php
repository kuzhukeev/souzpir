<?php

use App\Seller\Analytics\ScheduleAnalytics;
use App\Seller\Analytics\SendAnalyticsDigests;

return ['commands' => [SendAnalyticsDigests::class], 'schedules' => [ScheduleAnalytics::class]];
