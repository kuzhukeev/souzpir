<?php

use App\Shell\Context\OrganizationSelectController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/context/organization', [OrganizationSelectController::class, 'index'])->name('context.organization');
        Route::get('/context/organization/logo', [OrganizationSelectController::class, 'logo'])->name('context.organization.logo');
    });
}];
