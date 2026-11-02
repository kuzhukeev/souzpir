<?php

use App\Vision\Reports\ReportsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/vision/report', [ReportsController::class, 'index'])->name('vision.reports.index');
        Route::get('/vision/report/data', [ReportsController::class, 'data'])->name('vision.reports.data');
        Route::post('/vision/report', [ReportsController::class, 'create'])->name('vision.reports.create');
    });
}];
