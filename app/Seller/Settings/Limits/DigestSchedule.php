<?php

namespace App\Seller\Settings\Limits;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class DigestSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:notification-digests')->everyMinute()->withoutOverlapping();
        if (config('runtime.notification_driver') === 'fake') {
            $schedule->command('notifications:drain')->everyMinute()->withoutOverlapping();
        }
    }
}
