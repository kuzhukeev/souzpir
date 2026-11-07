<?php

use App\Shell\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/attention', [DashboardController::class, 'attention'])->name('dashboard.attention');
        Route::get('/notification-center', [DashboardController::class, 'notificationCenter'])->name('dashboard.notification-center');
        Route::post('/notification-center/read-all', [DashboardController::class, 'readAll'])->name('dashboard.notifications.read-all');
    });
}];
