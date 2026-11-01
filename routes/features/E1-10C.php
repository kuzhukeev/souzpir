<?php

use App\Shell\Integrations\IntegrationsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::put('/integrations/notifications', [IntegrationsController::class, 'saveNotifications'])->name('integrations.notifications.save');
        Route::post('/integrations/notifications/test', [IntegrationsController::class, 'testNotifications'])->name('integrations.notifications.test');
    });
}];
