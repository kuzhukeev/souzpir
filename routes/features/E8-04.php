<?php

use App\Vision\Measurements\MeasurementsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/vision/runs', [MeasurementsController::class, 'index'])->name('vision.measurements.index');
            Route::get('/vision/runs/data', [MeasurementsController::class, 'data'])->name('vision.measurements.data');
            Route::get('/vision/runs/revision', [MeasurementsController::class, 'revision'])->name('vision.measurements.revision');
        });
    },
];
