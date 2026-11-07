<?php

use App\Seller\Followups\FollowupsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/followups', [FollowupsController::class, 'index'])->name('seller.followups.index');
        Route::post('/seller/followups/schedule', [FollowupsController::class, 'schedule'])->name('seller.followups.schedule');
        Route::post('/seller/followups/consent/{conversation}', [FollowupsController::class, 'consent'])->name('seller.followups.consent');
        Route::post('/seller/followups/{candidate}/cancel', [FollowupsController::class, 'cancel'])->name('seller.followups.cancel');
    });
}];
