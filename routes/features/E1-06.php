<?php

use App\Shell\Team\Http\RoleMatrixController;
use App\Shell\Team\Http\RoleMatrixPageController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/team/roles', RoleMatrixPageController::class)->name('team.roles.show');
            Route::get('/team/roles/matrix', RoleMatrixController::class)->name('team.roles.matrix');
        });
    },
];
