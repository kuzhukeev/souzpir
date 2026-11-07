<?php

use App\Seller\Sandbox\SandboxController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/sandbox', [SandboxController::class, 'index'])->name('seller.sandbox.index');
        Route::post('/seller/knowledge/sandbox/reply', [SandboxController::class, 'reply'])->name('seller.sandbox.reply');
        Route::post('/seller/knowledge/sandbox/check', [SandboxController::class, 'check'])->name('seller.sandbox.check');
    });
}];
