<?php

use App\Seller\Calibration\CalibrationController;
use App\Seller\Calibration\CompareController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/quality/compare', [CompareController::class, 'index'])->middleware(['auth.version', 'auth.nostore'])->name('seller.quality.compare');
    Route::get('/seller/quality/calibration', [CalibrationController::class, 'index'])->middleware(['auth.version', 'auth.nostore'])->name('seller.quality.calibration');
    Route::post('/seller/quality/calibration', [CalibrationController::class, 'save'])->middleware(['auth.version', 'auth.nostore'])->name('seller.quality.calibration.save');
}];
