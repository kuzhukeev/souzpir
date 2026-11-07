<?php

use App\Shell\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/attention', [DashboardController::class, 'attention'])->name('dashboard.attention');
        Route::post('/attention/{kind}/snooze', [DashboardController::class, 'snoozeAttention'])->where('kind', '[a-z0-9_]+')->name('dashboard.attention.snooze');
        Route::get('/notification-center', [DashboardController::class, 'notificationCenter'])->name('dashboard.notification-center');
        Route::get('/notifications', [DashboardController::class, 'notificationCenter'])->name('dashboard.notifications');
        Route::post('/notification-center/read-all', [DashboardController::class, 'readAll'])->name('dashboard.notifications.read-all');
    });
}];
