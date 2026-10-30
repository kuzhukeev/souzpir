<?php

use App\Http\Controllers\PreviewController;
use App\Http\Middleware\EnsureLocalPreview;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureLocalPreview::class)->group(function (): void {
    Route::get('/', [PreviewController::class, 'redirect'])->name('home');
    Route::get('/preview', [PreviewController::class, 'index'])->name('preview.foundation');
});
