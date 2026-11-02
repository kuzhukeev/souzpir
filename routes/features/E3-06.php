<?php

use App\Seller\Evaluation\EvaluationReadController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/seller/evaluations/{evaluation}', EvaluationReadController::class)->whereUlid('evaluation')
        ->middleware(['auth.version', 'auth.nostore'])->name('seller.evaluations.show');
}];
