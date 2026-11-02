<?php

use App\Seller\Settings\Policy\OffTopicController;
use App\Seller\Settings\Policy\PolicySettingsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/settings/policy', [PolicySettingsController::class, 'index'])->name('seller.settings.policy.index');
        Route::post('/seller/settings/off-topic', [OffTopicController::class, 'save'])->name('seller.settings.off-topic.save');
        Route::post('/seller/settings/followups', [PolicySettingsController::class, 'update'])->name('seller.settings.followups.update');
    });
}];
