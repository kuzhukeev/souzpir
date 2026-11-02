<?php

use App\Seller\Settings\Techniques\TechniquesController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/scenario/techniques', [TechniquesController::class, 'index'])->middleware(['auth.version', 'auth.nostore'])->name('seller.techniques.index');
    Route::post('/seller/scenario/techniques/quiet', [TechniquesController::class, 'quiet'])->middleware(['auth.version', 'auth.nostore'])->name('seller.techniques.quiet');
    Route::post('/seller/scenario/techniques/publish', [TechniquesController::class, 'publish'])->middleware(['auth.version', 'auth.nostore'])->name('seller.techniques.publish');
    Route::post('/seller/scenario/techniques/{technique}', [TechniquesController::class, 'save'])->middleware(['auth.version', 'auth.nostore'])->name('seller.techniques.save');
}];
