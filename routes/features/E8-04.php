<?php

use App\Vision\Measurements\MeasurementsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/vision/runs', [MeasurementsController::class, 'index'])->name('vision.measurements.index');
            Route::get('/vision/runs/data', [MeasurementsController::class, 'data'])->name('vision.measurements.data');
            Route::get('/vision/runs/revision', [MeasurementsController::class, 'revision'])->name('vision.measurements.revision');
            Route::put('/vision/runs/schedule', [MeasurementsController::class, 'schedule'])->name('vision.measurements.schedule');
            Route::post('/vision/runs', [MeasurementsController::class, 'start'])->name('vision.measurements.start');
            Route::post('/vision/runs/{measurement}/next', [MeasurementsController::class, 'next'])->name('vision.measurements.next');
            Route::post('/vision/runs/{measurement}/repeat', [MeasurementsController::class, 'repeat'])->name('vision.measurements.repeat');
            Route::post('/vision/runs/{measurement}/confirm', [MeasurementsController::class, 'confirm'])->whereUlid('measurement')->name('vision.measurements.confirm');
        });
    },
];
