<?php

namespace App\Shell\Auth\Http;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\EligibleAccount;
use App\Shell\Auth\InvitationExchangeGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SessionController
{
    public function create(): Response
    {
        return Inertia::render('Shell/Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string'], 'remember' => ['boolean']]);
        $key = 'auth.login:'.hash('sha256', $data['email']);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Слишком много попыток. Повторите через 15 минут.']);
        }
        $user = User::where('email', $data['email'])->first();
        $hash = $user?->password ?? '$2y$12$C6UzMDM.H6dfI/f/IKcEe.ouXKf.vGPlZ4eV1AJfs8.GvE0lXkz3u';
        if (! Hash::check($data['password'], $hash) || ! $user || ! app(EligibleAccount::class)->includes($data['email'])) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'Не удалось войти. Проверьте данные.']);
        }
        RateLimiter::clear($key);
        DB::transaction(function () use ($request): void {
            $oldId = $request->session()->getId();
            $request->session()->regenerate(true);
            app(InvitationExchangeGrants::class)->rotate($oldId, $request->session()->getId());
        });
        if ($user->mfa_confirmed_at !== null) {
            $request->session()->put('auth.mfa_pending', ['user_id' => $user->id, 'expires' => now()->addMinutes(5)->timestamp, 'version' => $user->auth_version, 'remember' => (bool) ($data['remember'] ?? false)]);

            return redirect()->route('mfa.challenge');
        }
        $this->complete($request, $user, (bool) ($data['remember'] ?? false), $user->auth_version);

        return redirect()->intended(route('auth.ready'));
    }

    public function complete(Request $request, User $user, bool $remember, ?int $expectedVersion = null): void
    {
        // New registration models have not yet loaded the database default (0).
        $expectedVersion ??= $user->auth_version ?? 0;
        $loginStarted = false;
        try {
            DB::transaction(function () use ($request, $user, $remember, $expectedVersion, &$loginStarted): void {
                $fresh = User::whereKey($user->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->auth_version !== $expectedVersion) {
                    throw ValidationException::withMessages(['email' => 'Данные авторизации изменились. Войдите заново.']);
                }
                // Laravel may create a remember token and queue its cookie during login.
                // Both operations must happen only after the version check under this lock.
                $loginStarted = true;
                $oldId = $request->session()->getId();
                Auth::login($fresh, $remember);
                $request->session()->regenerate();
                app(InvitationExchangeGrants::class)->rotate($oldId, $request->session()->getId());
                $request->session()->forget('auth.mfa_pending');
                $request->session()->put('auth.version', $fresh->auth_version);
                app(AuthSessionRegistry::class)->issue($request, $fresh, $expectedVersion);
            });
        } catch (Throwable $error) {
            if ($loginStarted) {
                Auth::logoutCurrentDevice();
                $request->session()->invalidate();
            }
            throw $error;
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        // The logout route bypasses the normal version middleware. Preserve its
        // remembered-login bootstrap before revoking this newly restored lease.
        if ($request->session()->get('auth.version') === null && Auth::viaRemember()) {
            app(AuthSessionRegistry::class)->issueRemembered($request, $request->user());
        }
        app(AuthSessionRegistry::class)->withinLogout($request, function (User $fresh) use ($request): void {
            app(AuthSessionRegistry::class)->revokeCurrent($request);
            app(InvitationExchangeGrants::class)->revoke($request);
            Auth::guard()->setUser($fresh);
            Auth::logout();
        });
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
