<?php

use App\Shell\Team\Http\InvitationRepeatController;
use App\Shell\Team\Http\InvitationRevocationController;
use App\Shell\Team\Http\RoleMatrixController;
use App\Shell\Team\Http\RoleMatrixPageController;
use App\Shell\Team\Http\TeamMemberController;
use App\Shell\Team\Http\TeamMembersController;
use App\Shell\Team\Http\TeamMemberUpdateController;
use App\Shell\Team\Http\TransferMemberDialogsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/team', TeamMembersController::class)->name('team.index');
            Route::get('/team/roles', RoleMatrixPageController::class)->name('team.roles.show');
            Route::get('/team/roles/matrix', RoleMatrixController::class)->name('team.roles.matrix');
            Route::post('/team/invitations/{invitation}/revoke', InvitationRevocationController::class)->whereUlid('invitation')->name('team.invitations.revoke');
            Route::post('/team/invitations/{invitation}/repeat', InvitationRepeatController::class)->whereUlid('invitation')->name('team.invitations.repeat');
            Route::post('/team/{membership}/transfer-dialogs', TransferMemberDialogsController::class)->whereUlid('membership')->name('team.dialogs.transfer');
            Route::get('/team/{membership}', TeamMemberController::class)->whereUlid('membership')->name('team.show');
            Route::patch('/team/{membership}', TeamMemberUpdateController::class)->whereUlid('membership')->name('team.update');
        });
    },
];
