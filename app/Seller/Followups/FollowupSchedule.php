<?php

namespace App\Seller\Followups;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class FollowupSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:prepare-followups')->everyThirtyMinutes();
    }
}
