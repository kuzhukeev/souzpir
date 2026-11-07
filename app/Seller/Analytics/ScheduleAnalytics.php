<?php

namespace App\Seller\Analytics;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class ScheduleAnalytics implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:analytics-fake-digest')->hourly();
    }
}
