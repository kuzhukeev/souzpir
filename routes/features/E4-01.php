<?php

use App\Seller\Knowledge\Releases\IncomingController;
use App\Seller\Knowledge\Releases\ReleasesController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge', [ReleasesController::class, 'index'])->name('seller.knowledge.index');
        Route::get('/seller/knowledge/releases/compare', [ReleasesController::class, 'compare'])->name('seller.knowledge.compare');
        Route::get('/seller/knowledge/runs/{run}', [ReleasesController::class, 'scenarioRun'])->whereUlid('run')->name('seller.knowledge.scenario-run');
        Route::post('/seller/knowledge/draft', [ReleasesController::class, 'draft'])->name('seller.knowledge.draft');
        Route::post('/seller/knowledge/incoming', [IncomingController::class, 'handle'])->name('seller.knowledge.incoming');
        Route::get('/seller/knowledge/{release}/backup', [ReleasesController::class, 'backup'])->whereUlid('release')->name('seller.knowledge.backup');
        Route::post('/seller/knowledge/{release}/scenarios', [ReleasesController::class, 'runScenarios'])->whereUlid('release')->name('seller.knowledge.scenarios');
        Route::post('/seller/knowledge/{release}/publish', [ReleasesController::class, 'publish'])->whereUlid('release')->name('seller.knowledge.publish');
        Route::post('/seller/knowledge/{release}/rollback', [ReleasesController::class, 'rollback'])->whereUlid('release')->name('seller.knowledge.rollback');
        Route::post('/seller/knowledge/{release}/restore', [ReleasesController::class, 'restore'])->whereUlid('release')->name('seller.knowledge.restore');
    });
}];
