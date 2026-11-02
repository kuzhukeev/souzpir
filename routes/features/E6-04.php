<?php

use App\Seller\Settings\Tools\ToolSettingsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/scenario/tools', [ToolSettingsController::class, 'tools'])->name('seller.tools.settings');
        Route::post('/seller/scenario/tools', [ToolSettingsController::class, 'updateTools'])->name('seller.tools.settings.update');
        Route::get('/seller/scenario/handoff', [ToolSettingsController::class, 'handoff'])->name('seller.handoff.settings');
        Route::post('/seller/scenario/handoff', [ToolSettingsController::class, 'updateHandoff'])->name('seller.handoff.settings.update');
    });
}];
