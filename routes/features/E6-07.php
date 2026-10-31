<?php

use App\Seller\Settings\Limits\DigestInboxController;
use App\Seller\Settings\Limits\LimitsController;
use App\Seller\Settings\Limits\NotificationsController;
use App\Seller\Settings\Limits\TestDeliveryController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/settings/limits', [LimitsController::class, 'index'])->name('seller.settings.limits');
    Route::get('/seller/settings/notifications', [NotificationsController::class, 'index'])->name('seller.settings.notifications');
    Route::put('/seller/settings/notifications', [NotificationsController::class, 'update'])->name('seller.settings.notifications.update');
    Route::get('/seller/settings/notifications/digests', DigestInboxController::class)->name('seller.settings.notifications.digests');
    Route::post('/seller/settings/notifications/test', TestDeliveryController::class)->middleware('throttle:10,1')->name('seller.settings.notifications.test');
}];
