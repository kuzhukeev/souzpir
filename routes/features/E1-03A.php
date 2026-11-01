<?php

use App\Shell\HomeEntry\HomeEntryController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->get('/dashboard', HomeEntryController::class)->name('dashboard');
    },
];
