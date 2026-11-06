<?php

use App\Vision\Profile\ProfileController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/vision', [ProfileController::class, 'startPage'])->name('vision.start');
            Route::get('/vision/private-access', [ProfileController::class, 'privateAccess'])->name('vision.private.access');
            Route::post('/vision/private-access', [ProfileController::class, 'verifyPrivateAccess'])->middleware('throttle:120,1,vision-private-access:')->name('vision.private.verify');
            Route::post('/vision/quiz/start', [ProfileController::class, 'start'])->middleware('throttle:20,1,vision-quiz-start:')->name('vision.quiz.start');
            Route::get('/vision/quiz/{step}/hints', [ProfileController::class, 'deferredHints'])->whereNumber('step')->name('vision.quiz.hints');
            Route::get('/vision/quiz/{step}', [ProfileController::class, 'quiz'])->whereNumber('step')->name('vision.quiz.show');
            Route::patch('/vision/quiz/step/{step}', [ProfileController::class, 'save'])->whereNumber('step')->middleware('throttle:90,1,vision-quiz-save:')->name('vision.quiz.save');
            Route::post('/vision/quiz/submit', [ProfileController::class, 'submit'])->middleware('throttle:20,1,vision-quiz-submit:')->name('vision.quiz.submit');
            Route::get('/vision/profile', [ProfileController::class, 'profile'])->name('vision.profile.show');
            Route::get('/vision/profile/{profileId}', [ProfileController::class, 'profile'])->whereUlid('profileId')->name('vision.profile.version');
            Route::patch('/vision/profile/step/{step}', [ProfileController::class, 'edit'])->whereNumber('step')->middleware('throttle:60,1,vision-profile-edit:')->name('vision.profile.edit');
        });
    },
];
