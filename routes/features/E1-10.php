<?php

use App\Shell\Integrations\CompanyNotifications\CompanyTelegramController;
use App\Shell\Integrations\IntegrationsController;
use App\Shell\Integrations\Keys\KeysController;
use App\Shell\Integrations\Webhooks\WebhookController;
use Illuminate\Support\Facades\Route;

return ['webhook' => static function (): void {
    Route::post('/company-telegram', [CompanyTelegramController::class, 'receive'])->name('integrations.telegram.webhook');
}, 'web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
        Route::get('/integrations/api', [IntegrationsController::class, 'api'])->block()->name('integrations.api');
        Route::get('/integrations/api/docs', [IntegrationsController::class, 'apiDocumentation'])->name('integrations.api.docs');
        Route::post('/integrations/api/secrets', [IntegrationsController::class, 'secret'])->block()->name('integrations.secrets.consume');
        Route::get('/integrations/notifications', [IntegrationsController::class, 'notifications'])->name('integrations.notifications');
        Route::post('/integrations/notifications/telegram/start', [CompanyTelegramController::class, 'start'])->middleware('throttle:5,10')->name('integrations.telegram.start');
        Route::post('/integrations/notifications/telegram/confirm', [CompanyTelegramController::class, 'confirm'])->middleware('throttle:10,1')->name('integrations.telegram.confirm');
        Route::post('/integrations/api/keys', [KeysController::class, 'store'])->block()->name('integrations.keys.store');
        Route::delete('/integrations/api/keys/{id}', [KeysController::class, 'destroy'])->name('integrations.keys.destroy');
        Route::post('/integrations/api/webhooks', [WebhookController::class, 'store'])->block()->name('integrations.webhooks.store');
        Route::post('/integrations/api/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('integrations.webhooks.test');
    });
}];
