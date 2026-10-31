<?php

namespace App\Shell\Auth\Http;

use App\Models\User;
use App\Shell\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MfaChallengeController
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Shell/Auth/Mfa');
    }

    public function store(Request $request, Totp $totp, SessionController $session): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('login');
        }
        $key = 'auth.mfa:'.hash('sha256', $pending['user_id']);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Слишком много попыток. Повторите через 15 минут.']);
        }
        $user = DB::transaction(function () use ($pending, $data, $totp): ?User {
            $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
            if (! $user || ! $user->mfa_confirmed_at || ! $user->mfa_secret || $user->auth_version !== $pending['version']) {
                return null;
            }
            $counter = $totp->matchingCounter($user->mfa_secret, (string) $data['code'], now()->timestamp, $user->mfa_last_counter);
            if ($counter === null) {
                return null;
            }
            $user->forceFill(['mfa_last_counter' => $counter])->save();

            return $user;
        });
        if (! $user) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['code' => 'Неверный код.']);
        }
        RateLimiter::clear($key);
        $session->complete($request, $user, $pending['remember']);

        return redirect()->intended(route('auth.ready'));
    }

    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('auth.mfa_pending');
        if (! is_array($pending) || ! is_string($pending['user_id'] ?? null) || ! is_int($pending['expires'] ?? null) || $pending['expires'] <= now()->timestamp || ! is_int($pending['version'] ?? null) || ! is_bool($pending['remember'] ?? null)) {
            $request->session()->forget('auth.mfa_pending');

            return null;
        }

        return $pending;
    }
}
