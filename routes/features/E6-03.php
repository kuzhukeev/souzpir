<?php

use App\Seller\Settings\Policy\ClientFieldsController;
use App\Seller\Settings\Policy\FieldRequestController;
use App\Seller\Settings\Policy\OffTopicController;
use App\Seller\Settings\Policy\PolicySettingsController;
use App\Seller\Settings\Policy\ReactionController;
use App\Seller\Settings\Policy\SlotOrderController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/settings/policy', [PolicySettingsController::class, 'index'])->name('seller.settings.policy.index');
        Route::get('/seller/scenario/fields', [PolicySettingsController::class, 'fields'])->name('seller.scenario.fields');
        Route::post('/seller/scenario/fields', [ClientFieldsController::class, 'save'])->name('seller.scenario.fields.save');
        Route::post('/seller/scenario/fields/request', [FieldRequestController::class, 'store'])->name('seller.scenario.fields.request');
        Route::post('/seller/settings/slot-order', [SlotOrderController::class, 'save'])->name('seller.settings.slot-order.save');
        Route::post('/seller/settings/reactions', [ReactionController::class, 'save'])->name('seller.settings.reactions.save');
        Route::post('/seller/settings/off-topic', [OffTopicController::class, 'save'])->name('seller.settings.off-topic.save');
        Route::post('/seller/settings/followups', [PolicySettingsController::class, 'update'])->name('seller.settings.followups.update');
    });
}];
