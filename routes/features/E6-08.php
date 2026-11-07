<?php

use App\Seller\Catalog\CatalogController;
use App\Seller\Catalog\YmlController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/integrations/catalog', [CatalogController::class, 'index'])->name('seller.catalog.index');
        Route::get('/seller/integrations/catalog/settings', [CatalogController::class, 'settingsPage'])->name('seller.catalog.settings');
        Route::post('/seller/integrations/catalog/import', [CatalogController::class, 'upload'])->name('seller.catalog.import');
        Route::delete('/seller/integrations/catalog/items/{item}', [CatalogController::class, 'deleteItem'])->name('seller.catalog.items.delete');
        Route::post('/seller/integrations/catalog/price-file', [CatalogController::class, 'priceFile'])->name('seller.catalog.price-file');
        Route::post('/seller/integrations/catalog/price-file/send', [CatalogController::class, 'sendTestPrice'])->name('seller.catalog.price-file.send');
        Route::post('/seller/integrations/catalog/yml', [YmlController::class, 'save'])->name('seller.catalog.yml.save');
        Route::post('/seller/integrations/catalog/yml/refresh', [YmlController::class, 'refresh'])->name('seller.catalog.yml.refresh');
        Route::get('/seller/integrations/stock', [CatalogController::class, 'stock'])->name('seller.catalog.stock');
        Route::post('/seller/integrations/stock', [CatalogController::class, 'stockSettings'])->name('seller.catalog.stock.update');
    });
}];
