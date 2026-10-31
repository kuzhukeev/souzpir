<?php

use App\Shell\Data\DataController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/settings/data', [DataController::class, 'index'])->name('data.index');
        Route::patch('/settings/data/consents', [DataController::class, 'consents'])->name('data.consents');
        Route::post('/settings/data/exports', [DataController::class, 'create'])->name('data.exports.create');
        Route::get('/settings/data/exports/{id}/download', [DataController::class, 'download'])->name('data.exports.download');
    });
}];
