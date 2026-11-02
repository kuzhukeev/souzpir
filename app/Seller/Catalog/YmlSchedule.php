<?php

namespace App\Seller\Catalog;

use App\Providers\Features\ScheduleContributor;
use Illuminate\Console\Scheduling\Schedule;

final class YmlSchedule implements ScheduleContributor
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('seller:refresh-yml-feeds')->everyThirtyMinutes();
    }
}
