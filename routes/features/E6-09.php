<?php

use App\Seller\Channels\Crm\CrmController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/integrations/crm', CrmController::class)->middleware(['auth.version', 'auth.nostore'])->name('seller.integrations.crm');
}];
