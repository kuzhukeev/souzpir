<?php

use App\Shell\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/dashboard/overview', [DashboardController::class, 'index'])->name('dashboard.overview');
        Route::post('/dashboard/notifications/{kind}/{id}/read', [DashboardController::class, 'read'])->name('dashboard.notifications.read');
    });
}];
