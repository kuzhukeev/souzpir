<?php

use App\Shell\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    },
];
