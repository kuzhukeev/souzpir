<?php

use App\Shell\Auth\EmailConfirmationBoundary;
use App\Shell\Auth\Http\MfaSettingsController;
use App\Shell\Profile\EmailController;
use App\Shell\Profile\NotificationController;
use App\Shell\Profile\ProfileController;
use App\Shell\Profile\Security\MfaRecoveryController;
use App\Shell\Profile\Security\SecurityController;
use App\Shell\Profile\TelegramController;
use App\Shell\Profile\TestNotifications\TestNotificationController;
use Illuminate\Support\Facades\Route;

return [
    'auth' => static function (): void {
        Route::middleware(['profile.enabled', 'verified', 'auth.version', 'auth.nostore', 'profile.context'])->group(static function (): void {
            Route::get('/settings/profile', [ProfileController::class, 'show'])->name('profile.legacy.profile');
            Route::get('/settings/notifications', [NotificationController::class, 'show'])->name('profile.legacy.notifications');
            Route::get('/settings/security', [SecurityController::class, 'show'])->name('profile.legacy.security');
            Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
            Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1,profile-edit:')->name('profile.update');
            Route::get('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
            Route::post('/profile/avatar', [ProfileController::class, 'storeAvatar'])->middleware('throttle:10,1,profile-avatar:')->name('profile.avatar.store');
            Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->middleware('throttle:10,1,profile-avatar:')->name('profile.avatar.delete');
            Route::post('/profile/email', [EmailController::class, 'request'])->middleware('throttle:5,60,profile-email-request:')->name('profile.email.request');
            Route::delete('/profile/email', [EmailController::class, 'cancel'])->middleware('throttle:10,1,profile-email-cancel:')->name('profile.email.cancel');
            Route::get('/profile/email/confirm/{change}', [EmailController::class, 'show'])->middleware(['signed:relative', 'throttle:10,1,profile-email-confirm:'])->whereUlid('change')->name('profile.email.confirm');
            Route::post('/profile/email/confirm/{change}', [EmailController::class, 'confirm'])->middleware(['signed:relative', EmailConfirmationBoundary::class, 'throttle:10,1,profile-email-confirm:'])->whereUlid('change')->name('profile.email.commit');
            Route::get('/profile/notifications', [NotificationController::class, 'show'])->name('profile.notifications');
            Route::put('/profile/notifications', [NotificationController::class, 'update'])->middleware('throttle:30,1,profile-notification-settings:')->name('profile.notifications.update');
            Route::put('/profile/notifications/timing', [NotificationController::class, 'updateTimingDraft'])->middleware('throttle:20,1,profile-notification-timing:')->name('profile.notifications.timing.update');
            Route::post('/profile/notifications/test', TestNotificationController::class)->middleware('throttle:10,1,profile-notification-test:')->name('profile.notifications.test');
            Route::post('/profile/notifications/telegram', [TelegramController::class, 'start'])->middleware('throttle:5,10,profile-telegram-start:')->name('profile.telegram.start');
            Route::get('/profile/notifications/telegram/link', [TelegramController::class, 'link'])->middleware('throttle:10,1,profile-telegram-link:')->name('profile.telegram.link');
            Route::post('/profile/notifications/telegram/confirm', [TelegramController::class, 'confirm'])->middleware('throttle:10,1,profile-telegram-manage:')->name('profile.telegram.confirm');
            Route::delete('/profile/notifications/telegram', [TelegramController::class, 'disconnect'])->middleware('throttle:10,1,profile-telegram-manage:')->name('profile.telegram.disconnect');
            Route::get('/profile/security', [SecurityController::class, 'show'])->name('profile.security');
            Route::put('/profile/security/password', [SecurityController::class, 'password'])->middleware('throttle:6,1,profile-password:')->name('profile.security.password');
            Route::delete('/profile/security/sessions', [SecurityController::class, 'revokeOthers'])->middleware('throttle:20,1,profile-session:')->name('profile.security.sessions.others');
            Route::delete('/profile/security/sessions/{id}', [SecurityController::class, 'revoke'])->middleware('throttle:20,1,profile-session:')->name('profile.security.sessions.revoke');
            Route::post('/profile/security/mfa/setup', [MfaSettingsController::class, 'start'])->middleware('throttle:6,1,profile-mfa:')->name('profile.security.mfa.start');
            Route::delete('/profile/security/mfa/setup', [MfaSettingsController::class, 'cancel'])->middleware('throttle:6,1,profile-mfa:')->name('profile.security.mfa.cancel');
            Route::post('/profile/security/mfa/confirm', [MfaSettingsController::class, 'confirm'])->middleware('throttle:6,1,profile-mfa:')->name('profile.security.mfa.confirm');
            Route::post('/profile/security/mfa/disable', [MfaSettingsController::class, 'disable'])->middleware('throttle:6,1,profile-mfa:')->name('profile.security.mfa.disable');
            Route::get('/profile/security/mfa/recovery-local', [MfaRecoveryController::class, 'localPage'])->name('profile.security.mfa.recovery.local');
            Route::post('/profile/security/mfa/recovery', [MfaRecoveryController::class, 'issue'])->middleware('throttle:6,1,profile-mfa:')->name('profile.security.mfa.recovery');
        });
    },
    'webhook' => static function (): void {
        Route::post('/profile/telegram', [TelegramController::class, 'webhook'])->middleware(['profile.enabled', 'throttle:60,1,profile-telegram-webhook:'])->name('profile.telegram.webhook');
    },
];
