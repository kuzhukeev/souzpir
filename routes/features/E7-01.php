<?php

use App\Seller\Quality\QualityController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/quality', [QualityController::class, 'index'])->name('seller.quality.index');
        Route::post('/seller/quality/collect', [QualityController::class, 'collectToday'])->name('seller.quality.collect');
        Route::patch('/seller/quality/{day}/recommendation', [QualityController::class, 'recommendation'])->where('day', '\d{4}-\d{2}-\d{2}')->name('seller.quality.recommendation');
        Route::get('/seller/quality/{day}', [QualityController::class, 'day'])->where('day', '\d{4}-\d{2}-\d{2}')->name('seller.quality.day');
    });
}];
