<?php

use App\Seller\Channels\Planned\PlannedController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/channels/vk', [PlannedController::class, 'vk'])->name('seller.planned.vk');
        Route::get('/seller/channels/whatsapp', [PlannedController::class, 'whatsapp'])->name('seller.planned.whatsapp');
        Route::get('/seller/integrations/calendar', [PlannedController::class, 'calendar'])->name('seller.planned.calendar');
        Route::get('/seller/integrations/planned', [PlannedController::class, 'index'])->name('seller.planned.index');
        Route::post('/seller/planned/interests', [PlannedController::class, 'interest'])->name('seller.planned.interest');
        Route::put('/seller/planned/reminders', [PlannedController::class, 'reminders'])->name('seller.planned.reminders');
    });
}];
