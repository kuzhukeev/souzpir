<?php

use App\Seller\Knowledge\Conditions\ConditionsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/conditions', [ConditionsController::class, 'index'])->name('seller.knowledge.conditions.index');
        Route::post('/seller/knowledge/conditions', [ConditionsController::class, 'save'])->name('seller.knowledge.conditions.save');
    });
}];
