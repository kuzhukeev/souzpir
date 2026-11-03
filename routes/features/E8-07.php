<?php

use App\Vision\Settings\SettingsController;
use App\Vision\Settings\TrendsController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->get('/vision/settings', [SettingsController::class, 'index'])->name('vision.settings.index');
    Route::middleware(['auth.version', 'auth.nostore'])->put('/vision/settings/notifications', [SettingsController::class, 'notifications'])->name('vision.settings.notifications');
    Route::middleware(['auth.version', 'auth.nostore'])->get('/vision/trends', [TrendsController::class, 'index'])->name('vision.trends.index');
}];
