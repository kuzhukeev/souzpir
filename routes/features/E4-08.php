<?php

use App\Seller\Knowledge\Escalation\EscalationController;
use App\Seller\Knowledge\Fewshot\FewshotController;
use App\Seller\Knowledge\Imports\YamlImportsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::post('/seller/knowledge/imports/yaml/preview', [YamlImportsController::class, 'preview'])->name('seller.knowledge.yaml.preview');
        Route::post('/seller/knowledge/imports/yaml/publish', [YamlImportsController::class, 'publish'])->name('seller.knowledge.yaml.publish');
        Route::get('/seller/knowledge/escalation', [EscalationController::class, 'index'])->name('seller.knowledge.escalation.index');
        Route::put('/seller/knowledge/escalation', [EscalationController::class, 'save'])->name('seller.knowledge.escalation.save');
        Route::get('/seller/knowledge/fewshot', [FewshotController::class, 'index'])->name('seller.knowledge.fewshot.index');
        Route::post('/seller/knowledge/fewshot', [FewshotController::class, 'save'])->name('seller.knowledge.fewshot.save');
        Route::get('/seller/knowledge/fewshot/preview', [FewshotController::class, 'preview'])->name('seller.knowledge.fewshot.preview');
        Route::post('/seller/knowledge/fewshot/bad', [FewshotController::class, 'saveBad'])->name('seller.knowledge.fewshot.bad.save');
        Route::delete('/seller/knowledge/fewshot/bad', [FewshotController::class, 'deleteBad'])->name('seller.knowledge.fewshot.bad.delete');
        Route::get('/seller/knowledge/fewshot/dialogs', [FewshotController::class, 'dialogs'])->name('seller.knowledge.fewshot.dialogs');
        Route::get('/seller/knowledge/fewshot/dialogs/{dialog}', [FewshotController::class, 'messages'])->whereUlid('dialog')->name('seller.knowledge.fewshot.messages');
        Route::post('/seller/knowledge/fewshot/from-dialog', [FewshotController::class, 'fromDialog'])->name('seller.knowledge.fewshot.from-dialog');
    });
}];
