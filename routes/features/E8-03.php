<?php

use App\Vision\Questions\QuestionsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::get('/vision/questions', [QuestionsController::class, 'index'])->name('vision.questions.index');
            Route::get('/vision/questions/approve', [QuestionsController::class, 'approvePage'])->name('vision.questions.approve.page');
            Route::get('/vision/questions/active', [QuestionsController::class, 'active'])->name('vision.questions.active');
            Route::get('/vision/questions/history', [QuestionsController::class, 'history'])->name('vision.questions.history');
            Route::get('/vision/questions/data', [QuestionsController::class, 'data'])->name('vision.questions.data');
            Route::post('/vision/questions/candidates', [QuestionsController::class, 'add'])->middleware('throttle:60,1,vision-question-create:')->name('vision.questions.candidates.add');
            Route::post('/vision/questions/import-profile', [QuestionsController::class, 'importProfile'])->middleware('throttle:20,1,vision-question-import:')->name('vision.questions.import');
            Route::post('/vision/questions/copy-active', [QuestionsController::class, 'copyActive'])->middleware('throttle:20,1,vision-question-copy:')->name('vision.questions.copy');
            Route::patch('/vision/questions/candidates/{id}', [QuestionsController::class, 'change'])->whereUlid('id')->middleware('throttle:90,1,vision-question-update:')->name('vision.questions.candidates.change');
            Route::delete('/vision/questions/candidates/{id}', [QuestionsController::class, 'delete'])->whereUlid('id')->middleware('throttle:60,1,vision-question-delete:')->name('vision.questions.candidates.delete');
            Route::post('/vision/questions/approve', [QuestionsController::class, 'approve'])->middleware('throttle:20,1,vision-question-approve:')->name('vision.questions.approve');
        });
    },
];
