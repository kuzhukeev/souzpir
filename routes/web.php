<?php

use App\Http\Controllers\PreviewController;
use App\Http\Middleware\EnsureLocalPreview;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'auth.ready' : 'login'))->name('home');

Route::middleware(EnsureLocalPreview::class)->group(function (): void {
    Route::get('/preview', [PreviewController::class, 'index'])->name('preview.foundation');
});
