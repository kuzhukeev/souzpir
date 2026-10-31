<?php

use App\Shell\Journal\JournalController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');
    Route::get('/journal/export', [JournalController::class, 'export'])->name('journal.export');
    Route::get('/journal/notifications', [JournalController::class, 'notifications'])->name('journal.notifications');
    Route::post('/journal/notifications/{id}/retry', [JournalController::class, 'retry'])->name('journal.retry');
}];
