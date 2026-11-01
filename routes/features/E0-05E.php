<?php

use App\Shell\Billing\Http\AiRecoveryPageController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/billing/ai-recovery', AiRecoveryPageController::class)->name('billing.ai-recovery');
        });
    },
];
