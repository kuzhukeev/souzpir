<?php

use App\Seller\Dialogs\DialogReviewController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::post('/seller/dialogs/{dialog}/messages/{message}/reviews', [DialogReviewController::class, 'store'])
        ->whereUlid('dialog')->whereUlid('message')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.reviews.store');
}];
