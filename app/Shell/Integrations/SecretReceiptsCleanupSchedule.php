<?php

namespace App\Shell\Integrations;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class SecretReceiptsCleanupSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('integrations:prune-secret-receipts')->everyFiveMinutes()->withoutOverlapping(10);
    }
}
