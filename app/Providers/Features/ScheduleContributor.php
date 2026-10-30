<?php

namespace App\Providers\Features;

use Illuminate\Console\Scheduling\Schedule;

interface ScheduleContributor
{
    public function register(Schedule $schedule): void;
}
