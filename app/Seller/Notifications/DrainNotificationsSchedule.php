<?php

namespace App\Seller\Notifications;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class DrainNotificationsSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('notifications:drain')->everyMinute()->withoutOverlapping();
    }
}
