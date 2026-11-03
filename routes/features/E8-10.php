<?php

use App\Vision\Clarifications\ClarificationController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/vision/runs/{measurement}/clarify/{step}', [ClarificationController::class, 'show'])
            ->whereUlid('measurement')->whereIn('step', ['mentions', 'presence', 'facts', 'done'])->name('vision.clarifications.show');
        Route::post('/vision/runs/{measurement}/clarify/{step}', [ClarificationController::class, 'save'])
            ->whereUlid('measurement')->whereIn('step', ['mentions', 'presence', 'facts'])->name('vision.clarifications.save');
    });
}];
