<?php

use App\Seller\Tasks\TransferTaskController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::post('/seller/tasks/{task}/transfer', TransferTaskController::class)
        ->middleware(['auth.version', 'auth.nostore'])->whereUlid('task')->name('seller.tasks.transfer');
}];
