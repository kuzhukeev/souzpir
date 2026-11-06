<?php

use App\Seller\Channels\Crm\CrmController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/integrations/crm', CrmController::class)->middleware(['auth.version', 'auth.nostore'])->name('seller.integrations.crm');
    Route::post('/seller/integrations/crm/{webhook}/mappings', [CrmController::class, 'mappings'])->middleware(['auth.version', 'auth.nostore'])->name('seller.integrations.crm.mappings');
    Route::post('/seller/integrations/crm/{webhook}/test', [CrmController::class, 'test'])->middleware(['auth.version', 'auth.nostore'])->name('seller.integrations.crm.test');
}];
