<?php

use App\Shell\MemberActivity\MemberLastLoginsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/team/member-last-logins', MemberLastLoginsController::class)->name('team.member-last-logins');
        });
    },
];
