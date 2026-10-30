<?php

namespace Tests\Feature\Smoke\Fixtures;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

class CountingSchedule implements ScheduleContributor
{
    public static int $registrations = 0;

    public function register(Schedule $schedule): void
    {
        self::$registrations++;
        $schedule->call(static fn () => null)->daily();
    }
}
