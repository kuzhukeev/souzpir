<?php

use App\ProtectedOperations\PendingSellerTurnsController;
use App\ProtectedOperations\RequestSellerTurnReviewController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/turns/pending', PendingSellerTurnsController::class)
        ->middleware(['auth.version', 'auth.nostore'])->name('seller.turns.pending');
    Route::get('/seller/turns/pending/{turn}', [PendingSellerTurnsController::class, 'show'])
        ->whereUlid('turn')->middleware(['auth.version', 'auth.nostore'])->name('seller.turns.pending.show');
    Route::post('/seller/turns/pending/{turn}/review', RequestSellerTurnReviewController::class)
        ->whereUlid('turn')->middleware(['auth.version', 'auth.nostore'])->name('seller.turns.pending.review');
}];
