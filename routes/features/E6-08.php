<?php

use App\Seller\Catalog\CatalogController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/integrations/catalog', [CatalogController::class, 'index'])->name('seller.catalog.index');
        Route::post('/seller/integrations/catalog/import', [CatalogController::class, 'upload'])->name('seller.catalog.import');
        Route::post('/seller/integrations/catalog/price-file', [CatalogController::class, 'priceFile'])->name('seller.catalog.price-file');
        Route::get('/seller/integrations/stock', [CatalogController::class, 'stock'])->name('seller.catalog.stock');
        Route::post('/seller/integrations/stock', [CatalogController::class, 'stockSettings'])->name('seller.catalog.stock.update');
    });
}];
