<?php

use Illuminate\Support\Facades\Route;

return [
    'preview' => static function (): void {
        Route::inertia('/login', 'Preview/Login')->name('preview.login');
        Route::inertia('/attention', 'Preview/Attention')->name('preview.attention');
    },
];
