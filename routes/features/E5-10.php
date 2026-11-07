<?php

use App\Seller\Reviews\ReviewsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/reviews', [ReviewsController::class, 'index'])->middleware(['auth.version', 'auth.nostore'])->name('seller.reviews.index');
    Route::post('/seller/reviews/dismiss', [ReviewsController::class, 'dismiss'])->middleware(['auth.version', 'auth.nostore'])->name('seller.reviews.dismiss');
}];
