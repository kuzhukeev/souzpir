<?php

use App\Seller\Customers\ImportExport\ImportExportController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/customer-duplicates', [ImportExportController::class, 'duplicates'])->name('seller.customers.merge.index');
        Route::post('/seller/customer-duplicates/distinct', [ImportExportController::class, 'distinct'])->name('seller.customers.merge.distinct');
        Route::post('/seller/customer-duplicates', [ImportExportController::class, 'merge'])->name('seller.customers.merge.store');
        Route::get('/seller/customer-io', [ImportExportController::class, 'index'])->name('seller.customers.io');
        Route::post('/seller/customer-io/preview', [ImportExportController::class, 'preview'])->name('seller.customers.io.preview');
        Route::post('/seller/customer-io/import', [ImportExportController::class, 'import'])->name('seller.customers.io.import');
    });
}];
