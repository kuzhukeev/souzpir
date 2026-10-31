<?php

use App\Shell\Onboarding\Http\LogoController;
use App\Shell\Onboarding\Http\OnboardingController;
use Illuminate\Support\Facades\Route;

return [
    'auth' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore', 'verified', 'onboarding.owner'])->group(static function (): void {
            Route::get('/start', [OnboardingController::class, 'show'])->name('onboarding.show');
            Route::post('/start/draft', [OnboardingController::class, 'draft'])->name('onboarding.draft');
            Route::post('/start/logo', [LogoController::class, 'store'])->middleware('throttle:20,1')->name('onboarding.logo.store');
            Route::get('/start/logo', [LogoController::class, 'show'])->name('onboarding.logo.show');
            Route::post('/start', [OnboardingController::class, 'finish'])->name('onboarding.finish');
        });
    },
];
