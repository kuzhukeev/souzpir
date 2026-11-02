<?php

namespace App\Seller\Customers;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class PromiseSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:notify-due-promises')->everyMinute();
    }
}
