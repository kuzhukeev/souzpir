<?php

use App\Seller\Scenarios\ScenariosController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/quality/scenarios', [ScenariosController::class, 'index'])->name('seller.scenarios.index');
        Route::post('/seller/quality/scenarios', [ScenariosController::class, 'save'])->name('seller.scenarios.save');
    });
}];
