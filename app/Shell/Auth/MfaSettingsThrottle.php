<?php

namespace App\Shell\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class MfaSettingsThrottle
{
    public function hit(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }
        $key = 'auth.mfa.settings:'.hash('sha256', (string) $user->getAuthIdentifier());
        Cache::lock($key.':lock', 10)->block(2, static function () use ($key): void {
            if (RateLimiter::tooManyAttempts($key, 6)) {
                throw new ThrottleRequestsException('Слишком много попыток. Повторите позже.', null, [
                    'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
                ]);
            }
            RateLimiter::hit($key, 60);
        });
    }
}
