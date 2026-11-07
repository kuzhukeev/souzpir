<?php

use App\Seller\Knowledge\Facts\FactsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/knowledge/facts', [FactsController::class, 'index'])->name('seller.knowledge.facts.index');
        Route::get('/seller/knowledge/facts/{fact}', [FactsController::class, 'show'])->whereUlid('fact')->name('seller.knowledge.facts.show');
        Route::post('/seller/knowledge/facts/group', [FactsController::class, 'group'])->name('seller.knowledge.facts.group');
        Route::post('/seller/knowledge/facts/group/delete', [FactsController::class, 'deleteGroup'])->name('seller.knowledge.facts.group.delete');
        Route::post('/seller/knowledge/facts', [FactsController::class, 'add'])->name('seller.knowledge.facts.add');
        Route::post('/seller/knowledge/facts/bulk', [FactsController::class, 'bulk'])->name('seller.knowledge.facts.bulk');
        Route::post('/seller/knowledge/facts/{fact}', [FactsController::class, 'update'])->whereUlid('fact')->name('seller.knowledge.facts.update');
        Route::post('/seller/knowledge/facts/{fact}/example', [FactsController::class, 'example'])->whereUlid('fact')->name('seller.knowledge.facts.example');
        Route::post('/seller/knowledge/facts/{fact}/delete', [FactsController::class, 'delete'])->whereUlid('fact')->name('seller.knowledge.facts.delete');
    });
}];
