<?php

use App\Seller\Channels\Email\EmailSettingsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/channels/email', [EmailSettingsController::class, 'index'])->name('seller.channels.email.index');
        Route::post('/seller/channels/email', [EmailSettingsController::class, 'update'])->name('seller.channels.email.update');
        Route::post('/seller/channels/email/test', [EmailSettingsController::class, 'test'])->name('seller.channels.email.test');
    });
}];
