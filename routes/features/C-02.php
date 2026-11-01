<?php

use App\ProtectedOperations\PendingSellerTurnsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/turns/pending', PendingSellerTurnsController::class)
        ->middleware(['auth.version', 'auth.nostore'])->name('seller.turns.pending');
}];
