<?php

use App\Shell\InvitationIssuance\InvitationIssuanceController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::post('/team/invitations', InvitationIssuanceController::class)
            ->middleware(['auth.version', 'auth.nostore', 'throttle:10,1'])
            ->name('team.invitations.issue');
    },
];
