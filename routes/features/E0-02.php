<?php

use App\Tenancy\BrandSwitchController;
use App\Tenancy\TokenApi\BrandReadController;
use Illuminate\Support\Facades\Route;

return [
    'api' => static function (): void {
        Route::get('/brand', BrandReadController::class)->middleware('token.ability:read')->name('api.v1.brand.show');
    },
    'web' => static function (): void {
        Route::post('/brand/switch', BrandSwitchController::class)->name('brand.switch');
    },
];
