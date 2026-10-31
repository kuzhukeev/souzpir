<?php

use App\Shell\Support\Http\SupportController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/help', [SupportController::class, 'help'])->name('help.index');
        Route::get('/help/status', [SupportController::class, 'status'])->name('help.status');
        Route::get('/help/support', [SupportController::class, 'index'])->name('help.support');
        Route::post('/help/support', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('help.support.store');
        Route::get('/help/support/{ticket}/attachments/{attachment}', [SupportController::class, 'download'])->whereUlid(['ticket', 'attachment'])->name('help.support.attachments.show');
        Route::post('/help/support/{ticket}/access/revoke', [SupportController::class, 'revoke'])->whereUlid('ticket')->name('help.support.access.revoke');
    });
}];
