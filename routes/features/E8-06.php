<?php

use App\Vision\Actions\EffectController;
use App\Vision\Site\Http\SiteController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            foreach (['/vision/site' => 'overview', '/vision/site/criteria' => 'criteria', '/vision/site/bots' => 'bots', '/vision/site/pages' => 'pages', '/vision/actions' => 'actions', '/vision/actions/history' => 'history'] as $uri => $section) {
                Route::get($uri, [SiteController::class, 'index'])->defaults('section', $section)->name('vision.site.'.$section);
            }
            Route::get('/vision/actions/effect', [EffectController::class, 'index'])->name('vision.actions.effect');
            Route::post('/vision/site/audit', [SiteController::class, 'audit'])->middleware('throttle:10,1')->name('vision.site.audit');
            Route::post('/vision/actions', [SiteController::class, 'store'])->name('vision.actions.store');
            Route::patch('/vision/actions/{id}', [SiteController::class, 'update'])->name('vision.actions.update');
            Route::delete('/vision/actions/{id}', [SiteController::class, 'destroy'])->name('vision.actions.destroy');
        });
    },
];
