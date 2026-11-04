<?php

use App\Seller\Dialogs\DialogMessageController;
use App\Seller\Dialogs\DialogProfileController;
use App\Seller\Dialogs\DialogsController;
use App\Seller\Dialogs\DialogTaskController;
use App\Seller\Dialogs\DialogTransferController;
use App\Seller\Dialogs\PendingPageController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/dialogs', [DialogsController::class, 'index'])->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.index');
    Route::get('/seller/dialogs/mine/{dialog?}', [DialogsController::class, 'mine'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.mine');
    Route::get('/seller/dialogs/pending', PendingPageController::class)->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.pending');
    Route::get('/seller/dialogs/{dialog}', [DialogsController::class, 'index'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.show');
    Route::get('/seller/dialogs/{dialog}/messages/{message}/attachment', [DialogMessageController::class, 'attachment'])->whereUlid(['dialog', 'message'])->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.messages.attachment');
    Route::post('/seller/dialogs/{dialog}/messages', [DialogMessageController::class, 'store'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.messages.store');
    Route::post('/seller/dialogs/{dialog}/tasks', [DialogTaskController::class, 'store'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.tasks.store');
    Route::post('/seller/dialogs/{dialog}/transfer', [DialogTransferController::class, 'transfer'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.transfer');
    Route::put('/seller/dialogs/{dialog}/profile', [DialogProfileController::class, 'update'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.profile');
    Route::post('/seller/dialogs/{dialog}/mode', [DialogsController::class, 'mode'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.mode');
}];
