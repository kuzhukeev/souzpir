<?php

use App\Seller\Knowledge\Topics\TopicsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/topics', [TopicsController::class, 'index'])->name('seller.knowledge.topics.index');
        Route::post('/seller/knowledge/topics/draft', [TopicsController::class, 'createDraft'])->name('seller.knowledge.topics.draft');
        Route::post('/seller/knowledge/topics', [TopicsController::class, 'save'])->name('seller.knowledge.topics.store');
        Route::put('/seller/knowledge/topics/{intent}', [TopicsController::class, 'save'])->name('seller.knowledge.topics.update');
    });
}];
