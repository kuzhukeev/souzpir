<?php

use App\Vision\Settings\TrendsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->get('/vision/trends', [TrendsController::class, 'index'])->name('vision.trends.index');
}];
