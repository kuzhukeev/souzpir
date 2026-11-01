<?php

use App\Seller\Conversations\Api\LeadReadController;
use Illuminate\Support\Facades\Route;

return ['api' => static function (): void {
    Route::middleware('token.ability:leads')->group(static function (): void {
        Route::get('/seller/leads', [LeadReadController::class, 'index'])->name('api.v1.seller.leads.index');
        Route::get('/seller/leads/{lead}', [LeadReadController::class, 'show'])->whereUlid('lead')->name('api.v1.seller.leads.show');
    });
}];
