<?php

use App\Seller\Scenarios\RunsController;
use App\Seller\Scenarios\ScenariosController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/quality/runs', [RunsController::class, 'index'])->name('seller.scenarios.runs');
        Route::post('/seller/quality/runs', [RunsController::class, 'store'])->name('seller.scenarios.runs.store');
        Route::get('/seller/quality/scenarios', [ScenariosController::class, 'index'])->name('seller.scenarios.index');
        Route::post('/seller/quality/scenarios', [ScenariosController::class, 'save'])->name('seller.scenarios.save');
        Route::post('/seller/quality/scenarios/{scenario}/copy', [ScenariosController::class, 'copy'])->whereUlid('scenario')->name('seller.scenarios.copy');
        Route::patch('/seller/quality/scenarios/{scenario}/set', [ScenariosController::class, 'toggle'])->whereUlid('scenario')->name('seller.scenarios.set');
    });
}];
