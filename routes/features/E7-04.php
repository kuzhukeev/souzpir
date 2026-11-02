<?php

use App\Seller\Analytics\AnalyticsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/analytics', [AnalyticsController::class, 'index'])->name('seller.analytics.index');
        Route::get('/seller/analytics/export.csv', [AnalyticsController::class, 'csv'])->name('seller.analytics.csv');
    });
}];
