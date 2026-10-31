<?php

use App\Shell\Auth\Http\InvitationController;
use App\Shell\Auth\Http\MfaChallengeController;
use App\Shell\Auth\Http\MfaSettingsController;
use App\Shell\Auth\Http\PasswordController;
use App\Shell\Auth\Http\ReadyController;
use App\Shell\Auth\Http\RegisterController;
use App\Shell\Auth\Http\SessionController;
use App\Shell\Auth\Http\VerificationController;
use Illuminate\Support\Facades\Route;

return [
    'guest' => static function (): void {
        Route::middleware('auth.nostore')->group(static function (): void {
            Route::get('/login', [SessionController::class, 'create'])->name('login');
            Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
            Route::get('/register', [RegisterController::class, 'create'])->name('register');
            Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,60')->name('register.store');
            Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
            Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,60')->name('password.email');
            Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
            Route::post('/reset-password', [PasswordController::class, 'store'])->middleware('throttle:10,60')->name('password.store');
            Route::get('/mfa/challenge', [MfaChallengeController::class, 'create'])->name('mfa.challenge');
            Route::post('/mfa/challenge', [MfaChallengeController::class, 'store'])->middleware('throttle:20,1')->name('mfa.challenge.store');
        });
    },
    'session' => static function (): void {
        Route::middleware('auth.nostore')->group(static function (): void {
            Route::get('/invitations/open', [InvitationController::class, 'open'])->name('invitation.open');
            Route::post('/invitations/exchange', [InvitationController::class, 'exchange'])->middleware(['auth.version', 'throttle:10,1'])->name('invitation.exchange');
            Route::get('/invitations/show', [InvitationController::class, 'show'])->middleware('auth.version')->name('invitation.show');
            Route::post('/invitations/register', [InvitationController::class, 'register'])->middleware(['guest', 'throttle:5,60'])->name('invitation.register');
            Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware(['auth', 'auth.version', 'verified', 'throttle:10,1'])->name('invitation.accept');
        });
    },
    'auth' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
            Route::get('/email/verify', [VerificationController::class, 'notice'])->name('verification.notice');
            Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'confirm'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
            Route::post('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.confirm');
            Route::post('/email/verification-notification', [VerificationController::class, 'send'])->middleware('throttle:5,1')->name('verification.send');
            Route::get('/auth/ready', [ReadyController::class, 'show'])->middleware('verified')->name('auth.ready');
            Route::post('/mfa/setup', [MfaSettingsController::class, 'start'])->middleware(['verified', 'throttle:6,1'])->name('mfa.start');
            Route::get('/mfa/setup', [MfaSettingsController::class, 'show'])->middleware('verified')->name('mfa.setup');
            Route::post('/mfa/setup/confirm', [MfaSettingsController::class, 'confirm'])->middleware(['verified', 'throttle:6,1'])->name('mfa.confirm');
            Route::post('/mfa/disable', [MfaSettingsController::class, 'disable'])->middleware(['verified', 'throttle:6,1'])->name('mfa.disable');
        });
    },
];
