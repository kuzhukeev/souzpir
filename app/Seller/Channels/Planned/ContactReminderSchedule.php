<?php

namespace App\Seller\Channels\Planned;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class ContactReminderSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:contact-reminders')->everyMinute()->withoutOverlapping();
    }
}
