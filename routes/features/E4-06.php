<?php

use App\Seller\Knowledge\Imports\ImportsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/imports', [ImportsController::class, 'index'])->name('seller.knowledge.imports.index');
        Route::post('/seller/knowledge/imports/preview', [ImportsController::class, 'preview'])->name('seller.knowledge.imports.preview');
        Route::post('/seller/knowledge/imports', [ImportsController::class, 'save'])->name('seller.knowledge.imports.save');
    });
}];
