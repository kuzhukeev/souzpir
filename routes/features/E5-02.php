<?php

use App\Seller\Channels\Widget\PublicWidgetController;
use App\Seller\Channels\Widget\WidgetSettingsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/seller/channels/widget', [WidgetSettingsController::class, 'index'])->name('seller.widget.index');
            Route::post('/seller/channels/widget', [WidgetSettingsController::class, 'update'])->name('seller.widget.update');
        });
    },
    'public' => static function (): void {
        Route::get('/widget.js', [PublicWidgetController::class, 'loader'])->name('widget.loader');
        Route::get('/widget/config', [PublicWidgetController::class, 'config'])->name('widget.config');
    },
];
