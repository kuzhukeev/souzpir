<?php

use App\Vision\Settings\PersonalReportAccessController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::post('/vision/settings/personal-report-access', [PersonalReportAccessController::class, 'create'])->name('vision.settings.personal-report-access.create');
        Route::delete('/vision/settings/personal-report-access/{access}', [PersonalReportAccessController::class, 'revoke'])->whereUlid('access')->name('vision.settings.personal-report-access.revoke');
    });
}];
