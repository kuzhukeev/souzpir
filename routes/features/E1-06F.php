<?php

use App\Seller\Tasks\TaskTransferPageController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/tasks/transfer', TaskTransferPageController::class)
        ->middleware(['auth.version', 'auth.nostore'])->name('seller.tasks.transfer.page');
}];
