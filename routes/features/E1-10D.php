<?php

use App\Seller\Conversations\Api\ConversationReadController;
use Illuminate\Support\Facades\Route;

return ['api' => static function (): void {
    Route::middleware('token.ability:read')->group(static function (): void {
        Route::get('/seller/conversations', [ConversationReadController::class, 'index'])->name('api.v1.seller.conversations.index');
        Route::get('/seller/conversations/{conversation}', [ConversationReadController::class, 'show'])->whereUlid('conversation')->name('api.v1.seller.conversations.show');
    });
}];
