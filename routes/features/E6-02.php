<?php

use App\Seller\Settings\Greetings\GreetingsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/settings/greetings', [GreetingsController::class, 'index'])->name('seller.settings.greetings.index');
        Route::post('/seller/settings/greetings', [GreetingsController::class, 'update'])->name('seller.settings.greetings.update');
    });
}];
