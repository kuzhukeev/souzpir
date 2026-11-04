<?php

use App\Seller\Knowledge\Prohibitions\ProhibitionsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/prohibitions', [ProhibitionsController::class, 'index'])->name('seller.knowledge.prohibitions.index');
        Route::post('/seller/knowledge/prohibitions', [ProhibitionsController::class, 'save'])->name('seller.knowledge.prohibitions.save');
        Route::post('/seller/knowledge/prohibitions/check', [ProhibitionsController::class, 'check'])->name('seller.knowledge.prohibitions.check');
    });
}];
