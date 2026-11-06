<?php

use App\Vision\Measurements\MeasurementSubscriptionController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/vision/runs/{measurement}/subscription', [MeasurementSubscriptionController::class, 'show'])->whereUlid('measurement')->name('vision.measurements.subscription.show');
        Route::put('/vision/runs/{measurement}/subscription', [MeasurementSubscriptionController::class, 'update'])->whereUlid('measurement')->name('vision.measurements.subscription.update');
    });
}];
