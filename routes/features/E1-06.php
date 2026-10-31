<?php

use App\Shell\Team\Http\RoleMatrixController;
use App\Shell\Team\Http\RoleMatrixPageController;
use App\Shell\Team\Http\TeamMemberController;
use App\Shell\Team\Http\TeamMembersController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/team', TeamMembersController::class)->name('team.index');
            Route::get('/team/roles', RoleMatrixPageController::class)->name('team.roles.show');
            Route::get('/team/roles/matrix', RoleMatrixController::class)->name('team.roles.matrix');
            Route::get('/team/{membership}', TeamMemberController::class)->whereUlid('membership')->name('team.show');
        });
    },
];
