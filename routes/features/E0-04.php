<?php

use App\Shell\Notifications\Http\NotificationSettingsController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::patch('/notifications/settings', [NotificationSettingsController::class, 'update'])->name('notifications.settings.update');
    },
];
