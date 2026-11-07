<?php

use App\Seller\Channels\Api\ApiFailuresController;
use App\Seller\Channels\Api\ApiSettingsController;
use App\Seller\Channels\Api\LeadIngressController;
use App\Seller\Channels\Api\LogApiFailure;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/seller/channels/api/failures', ApiFailuresController::class)->name('seller.api.failures');
            Route::get('/seller/channels/api', [ApiSettingsController::class, 'index'])->name('seller.api.settings');
            Route::post('/seller/channels/api', [ApiSettingsController::class, 'update'])->name('seller.api.settings.update');
            Route::post('/seller/channels/api/action', [ApiSettingsController::class, 'updateAction'])->name('seller.api.settings.action');
        });
    },
    'api' => static function (): void {
        Route::post('/leads', [LeadIngressController::class, 'lead'])->middleware([LogApiFailure::class, 'token.ability:leads'])->name('api.v1.leads.create');
        Route::post('/turn', [LeadIngressController::class, 'turn'])->middleware([LogApiFailure::class, 'token.ability:turn'])->name('api.v1.turn.create');
    },
];
