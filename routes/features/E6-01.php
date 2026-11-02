<?php

use App\Seller\Settings\Persona\PersonaController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/persona', [PersonaController::class, 'index'])->name('seller.persona.index');
        Route::post('/seller/persona', [PersonaController::class, 'save'])->name('seller.persona.save');
    });
}];
