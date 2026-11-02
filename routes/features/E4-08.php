<?php

use App\Seller\Knowledge\Fewshot\FewshotController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/fewshot', [FewshotController::class, 'index'])->name('seller.knowledge.fewshot.index');
        Route::post('/seller/knowledge/fewshot', [FewshotController::class, 'save'])->name('seller.knowledge.fewshot.save');
        Route::get('/seller/knowledge/fewshot/preview', [FewshotController::class, 'preview'])->name('seller.knowledge.fewshot.preview');
    });
}];
