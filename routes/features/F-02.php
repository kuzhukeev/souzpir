<?php

use Illuminate\Support\Facades\Route;

return [
    'dev' => static function (): void {
        Route::inertia('/ds', 'Dev/DesignSystem')->name('dev.design-system');
    },
];
