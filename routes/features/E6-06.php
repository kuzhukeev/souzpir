<?php

use App\Seller\Settings\Schedule\ScheduleController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/settings', [ScheduleController::class, 'index'])->name('seller.schedule.index');
        Route::post('/seller/settings/schedule', [ScheduleController::class, 'updateSchedule'])->name('seller.schedule.update');
        Route::post('/seller/settings/assignment', [ScheduleController::class, 'updateAssignment'])->name('seller.assignment.update');
    });
}];
