<?php

use App\Vision\Competitors\CompetitorsController;
use App\Vision\Sources\SourcesController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/vision/competitors', [CompetitorsController::class, 'index'])->name('vision.competitors.index');
        Route::post('/vision/competitors/merge', [CompetitorsController::class, 'merge'])->name('vision.competitors.merge');
        Route::post('/vision/competitors/track', [CompetitorsController::class, 'track'])->name('vision.competitors.track');
        Route::get('/vision/sources', [SourcesController::class, 'index'])->name('vision.sources.index');
        Route::post('/vision/sources/presence', [SourcesController::class, 'presence'])->name('vision.sources.presence');
    });
}];
