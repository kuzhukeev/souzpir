<?php

namespace App\Seller\Settings\Tools;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class HandoffSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:monitor-handoffs')->everyMinute();
    }
}
