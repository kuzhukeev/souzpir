<?php

use App\Seller\Evaluation\DailySupervisor;
use Illuminate\Support\Facades\Schedule;

// FeatureRegistry registers explicit commands and permitted scheduler contributors.
// Installing dependencies does not start workers, schedule jobs, or send messages.
if (config('runtime.features_scheduling_enabled', false)) {
    Schedule::call(fn () => app(DailySupervisor::class)->run())->daily()->timezone('Europe/Moscow')->name('seller-evaluation-supervisor');
}
