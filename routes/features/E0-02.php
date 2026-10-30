<?php

use App\Tenancy\BrandSwitchController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::post('/brand/switch', BrandSwitchController::class)->name('brand.switch');
    },
];
