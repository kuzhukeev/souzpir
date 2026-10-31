<?php

namespace App\Shell\Auth\Http;

use App\Shell\Auth\MfaChallenge;
use App\Shell\Auth\MfaRecoveryTransport;
use App\Shell\Auth\SecureAccountContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(#[\SensitiveParameter] Request $request, SessionController $session): RedirectResponse
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('login');
        }
        $safeCode = $request->attributes->get('mfa.safe_code');
        if (is_array($safeCode) && ($safeCode['kind'] ?? null) === 'recovery') {
            if (! MfaRecoveryTransport::allows($request)) {
                throw ValidationException::withMessages(['code' => SecureAccountContext::MESSAGE]);
            }
        }
        $user = app(MfaChallenge::class)->verify($pending, is_array($safeCode) ? $safeCode : null);
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'Неверный код.']);
        }
        $session->complete($request, $user, $pending['remember'], $pending['version']);

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
