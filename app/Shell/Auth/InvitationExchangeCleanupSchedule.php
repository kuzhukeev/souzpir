<?php

namespace App\Shell\Auth;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class InvitationExchangeCleanupSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('auth:prune-invitation-exchanges')->hourly()->withoutOverlapping();
    }
}
