<?php

use App\Shell\Brands\Http\BrandSettingsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->prefix('/brands/{brand}/settings')->group(static function (): void {
            Route::get('/', [BrandSettingsController::class, 'show'])->name('brands.settings.show');
            Route::patch('/general', [BrandSettingsController::class, 'general'])->name('brands.settings.general');
            Route::patch('/theme', [BrandSettingsController::class, 'theme'])->name('brands.settings.theme');
            Route::get('/logo', [BrandSettingsController::class, 'logo'])->name('brands.settings.logo');
            Route::post('/domains', [BrandSettingsController::class, 'addDomain'])->name('brands.settings.domains.add');
            Route::post('/domains/{domain}/verify', [BrandSettingsController::class, 'verifyDomain'])->middleware('throttle:10,1')->name('brands.settings.domains.verify');
            Route::delete('/domains/{domain}', [BrandSettingsController::class, 'removeDomain'])->name('brands.settings.domains.remove');
            Route::post('/website/check', [BrandSettingsController::class, 'checkWebsite'])->middleware('throttle:10,1')->name('brands.settings.website.check');
        });
    },
];
