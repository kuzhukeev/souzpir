<?php

use App\Shell\Integrations\Webhooks\WebhookController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::post('/integrations/api/webhook-settings', [WebhookController::class, 'configure'])->block()->name('integrations.webhook-settings.store');
        Route::post('/integrations/api/webhook-settings/{webhook}/test', [WebhookController::class, 'fakeTest'])->name('integrations.webhook-settings.test');
    });
}];
