<?php

use App\Seller\Dialogs\ManagerSuggestionController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::post('/seller/dialogs/{dialog}/suggestion', [ManagerSuggestionController::class, 'generate'])->whereUlid('dialog')->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.suggestion');
}];
