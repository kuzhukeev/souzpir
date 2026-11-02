<?php

use App\Seller\Dialogs\DialogsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/dialogs', [DialogsController::class, 'index'])->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.index');
    Route::get('/seller/dialogs/{dialog}', [DialogsController::class, 'index'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.show');
    Route::post('/seller/dialogs/{dialog}/mode', [DialogsController::class, 'mode'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.mode');
}];
