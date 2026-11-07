<?php

use App\Seller\Channels\Widget\PublicWidgetController;
use App\Seller\Channels\Widget\WidgetContactController;
use App\Seller\Channels\Widget\WidgetSessionController;
use App\Seller\Channels\Widget\WidgetSettingsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/seller/channels/widget', [WidgetSettingsController::class, 'index'])->name('seller.widget.index');
            Route::get('/seller/channels/widget/{tab}', [WidgetSettingsController::class, 'index'])->whereIn('tab', ['install', 'look', 'behavior'])->name('seller.widget.tab');
            Route::post('/seller/channels/widget', [WidgetSettingsController::class, 'update'])->name('seller.widget.update');
            Route::post('/seller/channels/widget/check', [WidgetSettingsController::class, 'checkInstallation'])->name('seller.widget.check');
        });
    },
    'public' => static function (): void {
        Route::get('/widget.js', [PublicWidgetController::class, 'loader'])->name('widget.loader');
        Route::options('/widget/contact', [WidgetContactController::class, 'preflight'])->name('widget.contact.preflight');
        Route::post('/widget/contact', [WidgetContactController::class, 'store'])->name('widget.contact.store');
        Route::options('/widget/session', [WidgetSessionController::class, 'preflight'])->name('widget.session.preflight');
        Route::post('/widget/session', [WidgetSessionController::class, 'start'])->name('widget.session.start');
        Route::options('/widget/message', [WidgetSessionController::class, 'preflight'])->name('widget.message.preflight');
        Route::post('/widget/message', [WidgetSessionController::class, 'message'])->name('widget.message.store');
        Route::get('/widget/messages', [WidgetSessionController::class, 'messages'])->name('widget.messages');
        Route::get('/widget/config', [PublicWidgetController::class, 'config'])->name('widget.config');
    },
];
