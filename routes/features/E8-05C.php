<?php

use App\Vision\Publications\PublicationsController;
use App\Vision\Publications\PublicReportController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/vision/publications', [PublicationsController::class, 'index'])->name('vision.publications.index');
            Route::post('/vision/publications', [PublicationsController::class, 'create'])->name('vision.publications.create');
            Route::delete('/vision/publications/{publication}', [PublicationsController::class, 'revoke'])->whereUlid('publication')->name('vision.publications.revoke');
        });
    },
    'public' => static function (): void {
        Route::get('/v/{token}', PublicReportController::class)->where('token', '[a-f0-9]{64}')->name('vision.publications.public');
    },
];
