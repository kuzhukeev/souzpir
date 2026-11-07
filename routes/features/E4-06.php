<?php

use App\Seller\Knowledge\Imports\ImportsController;
use App\Seller\Knowledge\Imports\SiteImportsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/imports', [ImportsController::class, 'index'])->name('seller.knowledge.imports.index');
        Route::get('/seller/knowledge/imports/documents/{document}/download', [ImportsController::class, 'download'])->name('seller.knowledge.imports.documents.download');
        Route::get('/seller/knowledge/imports/documents/{document}/preview', [ImportsController::class, 'previewSaved'])->name('seller.knowledge.imports.documents.preview');
        Route::post('/seller/knowledge/imports/preview', [ImportsController::class, 'preview'])->name('seller.knowledge.imports.preview');
        Route::post('/seller/knowledge/imports', [ImportsController::class, 'save'])->name('seller.knowledge.imports.save');
        Route::post('/seller/knowledge/imports/site/preview', [SiteImportsController::class, 'preview'])->name('seller.knowledge.imports.site.preview');
        Route::post('/seller/knowledge/imports/site', [SiteImportsController::class, 'save'])->name('seller.knowledge.imports.site.save');
    });
}];
