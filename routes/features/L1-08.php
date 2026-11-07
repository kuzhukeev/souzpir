<?php

use App\Http\HealthController;
use Illuminate\Support\Facades\Route;

return [
    'public' => static function (): void {
        Route::get('/health', HealthController::class)->name('health');
    },
];
