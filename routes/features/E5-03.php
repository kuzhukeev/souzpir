<?php

use App\Seller\Channels\Api\ApiSettingsController;
use App\Seller\Channels\Api\LeadIngressController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/seller/channels/api', [ApiSettingsController::class, 'index'])->name('seller.api.settings');
            Route::post('/seller/channels/api', [ApiSettingsController::class, 'update'])->name('seller.api.settings.update');
        });
    },
    'api' => static function (): void {
        Route::post('/leads', [LeadIngressController::class, 'lead'])->middleware('token.ability:leads')->name('api.v1.leads.create');
        Route::post('/turn', [LeadIngressController::class, 'turn'])->middleware('token.ability:turn')->name('api.v1.turn.create');
    },
];
