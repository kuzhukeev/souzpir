<?php

use App\ProtectedOperations\SellerFollowup\TurnController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::post('/seller/turns/runtime', TurnController::class)->middleware(['auth.version', 'auth.nostore'])->name('seller.turns.runtime');
}];
