<?php

use App\Ai\Http\UnresolvedCallsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/ai/recovery/calls', UnresolvedCallsController::class)->name('ai.recovery.calls.index');
        });
    },
];
