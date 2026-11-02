<?php

use App\Seller\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller', [DashboardController::class, 'index'])->name('seller.dashboard.index');
        Route::get('/seller/attention', [DashboardController::class, 'attention'])->name('seller.dashboard.attention');
    });
}];
