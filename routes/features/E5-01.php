<?php

use App\Seller\Channels\Telegram\TelegramController;
use App\Seller\Channels\Telegram\TelegramRegistrationController;
use App\Seller\Channels\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/seller/channels', [TelegramController::class, 'index'])->name('seller.channels.index');
            Route::get('/seller/channels/telegram', [TelegramController::class, 'telegram'])->name('seller.channels.telegram');
            Route::post('/seller/channels/telegram/register', TelegramRegistrationController::class)->name('seller.channels.telegram.register');
            Route::put('/seller/channels/telegram', [TelegramController::class, 'save'])->name('seller.channels.telegram.save');
            Route::patch('/seller/channels/{channel}/mode', [TelegramController::class, 'mode'])->whereUlid('channel')->name('seller.channels.mode');
            Route::post('/seller/channels/stop', [TelegramController::class, 'stop'])->name('seller.channels.stop');
        });
    },
    'webhook' => static function (): void {
        Route::post('/seller/telegram/{channel}', TelegramWebhookController::class)->whereUlid('channel')->name('seller.telegram.webhook');
    },
];
