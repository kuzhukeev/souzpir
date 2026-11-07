<?php

use App\Seller\Dialogs\SendSuggestionController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::post('/seller/dialogs/{dialog}/suggestions/{turn}/send', [SendSuggestionController::class, 'store'])
        ->whereUlid(['dialog', 'turn'])->middleware(['auth.version', 'auth.nostore'])->name('seller.dialogs.suggestions.send');
}];
