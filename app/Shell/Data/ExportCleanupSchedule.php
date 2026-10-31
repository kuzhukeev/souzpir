<?php

namespace App\Shell\Data;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class ExportCleanupSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('data:prune-exports')->hourly()->withoutOverlapping();
    }
}
