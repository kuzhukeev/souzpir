<?php

use App\Shell\Team\Http\TeamController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/team', [TeamController::class, 'index'])->name('team.index');
            Route::get('/team/roles', [TeamController::class, 'roles'])->name('team.roles');
            Route::post('/team/invitations', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invitations.store');
            Route::post('/team/invitations/{invitation}/resend', [TeamController::class, 'resend'])->middleware('throttle:20,1')->whereUlid('invitation')->name('team.invitations.resend');
            Route::post('/team/invitations/{invitation}/revoke', [TeamController::class, 'revoke'])->whereUlid('invitation')->name('team.invitations.revoke');
            Route::get('/team/{membership}', [TeamController::class, 'show'])->whereUlid('membership')->name('team.show');
            Route::patch('/team/{membership}', [TeamController::class, 'update'])->whereUlid('membership')->name('team.update');
            Route::post('/team/{membership}/disable', [TeamController::class, 'disable'])->whereUlid('membership')->name('team.disable');
        });
    },
];
