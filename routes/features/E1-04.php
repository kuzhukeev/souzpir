<?php

use App\Shell\Organizations\Http\OrganizationController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/company', [OrganizationController::class, 'company'])->name('organization.show');
            Route::patch('/company', [OrganizationController::class, 'update'])->name('organization.update');
            Route::post('/company/transfer', [OrganizationController::class, 'transfer'])->name('organization.transfer');
            Route::get('/brands', [OrganizationController::class, 'brands'])->name('brands.index');
            Route::post('/brands', [OrganizationController::class, 'createBrand'])->name('brands.create');
            Route::patch('/brands/{brand}/archive', [OrganizationController::class, 'archiveBrand'])->name('brands.archive');
            Route::get('/brands/{brand}/logo', [OrganizationController::class, 'logo'])->name('brands.logo');
            Route::get('/brands/{brand}/modules', [OrganizationController::class, 'modules'])->name('brands.modules');
            Route::put('/brands/{brand}/modules/{module}', [OrganizationController::class, 'updateModule'])->name('brands.modules.update');
        });
    },
];
