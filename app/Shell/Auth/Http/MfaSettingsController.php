<?php

namespace App\Shell\Auth\Http;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\MfaSettingsThrottle;
use App\Shell\Auth\MfaSetupState;
use App\Shell\Auth\SecureAccountContext;
use App\Shell\Auth\Totp;
use App\Shell\Profile\ProfileValidation;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MfaSettingsController
{
    public function start(#[\SensitiveParameter] Request $request, Totp $totp): RedirectResponse
    {
        $this->limitAttempts($request);
        SecureAccountContext::validate($request, 'password');
        $data = ProfileValidation::validate($request, ['password' => ['required', 'current_password']]);
        $this->assertMember($request);
        $setup = $this->withinActive($request, function () use ($request, $data, $totp): array {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshSession($request, $user);
            $this->assertPassword($data['password'], $user);
            if ($user->mfa_confirmed_at) {
                throw ValidationException::withMessages(['password' => 'Двухфакторная проверка уже включена.']);
            }

            return ['user_id' => $user->id, 'version' => $user->auth_version, 'secret' => $totp->generateSecret(), 'expires' => now()->addMinutes(10)->timestamp];
        });
        $request->session()->put('auth.mfa_setup', $setup);

        return redirect()->route($request->routeIs('profile.security.mfa.*') ? 'profile.security' : 'mfa.setup');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        SecureAccountContext::assertRead($request);
        $this->assertMember($request);
        $setup = app(MfaSetupState::class)->pending($request);
        if (! $setup) {
            return redirect()->route($request->routeIs('profile.security.mfa.*') ? 'profile.security' : 'auth.ready');
        }

        Inertia::encryptHistory();

        return Inertia::render('Shell/Auth/MfaSetup', ['secret' => $setup['secret']]);
    }

    public function confirm(#[\SensitiveParameter] Request $request, Totp $totp): RedirectResponse
    {
        $this->limitAttempts($request);
        $data = ProfileValidation::validate($request, ['code' => ['required', 'digits:6']]);
        $org = $this->assertMember($request);
        $setup = app(MfaSetupState::class)->pending($request);
        if (! $setup) {
            return redirect()->route($request->routeIs('profile.security.mfa.*') ? 'profile.security' : 'auth.ready');
        }
        $counter = $totp->matchingCounter($setup['secret'], (string) $data['code'], now()->timestamp, null);
        if ($counter === null) {
            throw ValidationException::withMessages(['code' => 'Неверный код.']);
        }
        $updated = $this->withinActive($request, function () use ($request, $org, $setup, $counter): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshSession($request, $user);
            if ($setup['version'] !== $user->auth_version) {
                throw new AuthenticationException('Unauthenticated.');
            }
            if ($user->mfa_confirmed_at) {
                throw ValidationException::withMessages(['code' => 'Двухфакторная проверка уже включена.']);
            }
            $user->forceFill(['mfa_secret' => $setup['secret'], 'mfa_confirmed_at' => now(), 'mfa_last_counter' => $counter, 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            app(AuthSessionRegistry::class)->advanceVersion($request, $user->auth_version);
            app(AuditWriter::class)->record(new TenantContext($org, actorId: $user->id), new AuditEntry('account.mfa.enabled', 'user', $user->id));

            return $user;
        });
        $request->session()->forget('auth.mfa_setup');
        Inertia::clearHistory();
        Auth::guard()->setUser($updated);
        $request->session()->put('auth.version', $updated->auth_version);

        return redirect()->route($request->routeIs('profile.security.mfa.*') ? 'profile.security' : 'auth.ready')->with('status', 'Двухфакторная проверка включена.');
    }

    public function disable(#[\SensitiveParameter] Request $request, Totp $totp): RedirectResponse
    {
        $this->limitAttempts($request);
        $data = ProfileValidation::validate($request, ['password' => ['required', 'current_password'], 'code' => ['required', 'digits:6']]);
        $org = $this->assertMember($request);
        $updated = $this->withinActive($request, function () use ($request, $data, $totp, $org): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshSession($request, $user);
            $this->assertPassword($data['password'], $user);
            $counter = $user->mfa_secret ? $totp->matchingCounter($user->mfa_secret, (string) $data['code'], now()->timestamp, $user->mfa_last_counter) : null;
            if (! $user->mfa_confirmed_at || $counter === null) {
                throw ValidationException::withMessages(['code' => 'Неверный код.']);
            }
            $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_last_counter' => null, 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            DB::table('mfa_recovery_codes')->where('user_id', $user->id)->delete();
            app(AuthSessionRegistry::class)->advanceVersion($request, $user->auth_version);
            app(AuditWriter::class)->record(new TenantContext($org, actorId: $user->id), new AuditEntry('account.mfa.disabled', 'user', $user->id));

            return $user;
        });
        Auth::guard()->setUser($updated);
        $request->session()->put('auth.version', $updated->auth_version);

        Inertia::clearHistory();

        return redirect()->route($request->routeIs('profile.security.mfa.*') ? 'profile.security' : 'auth.ready')->with('status', 'Двухфакторная проверка отключена.');
    }

    /** Both the legacy and profile aliases share this per-account credential limit. */
    private function limitAttempts(Request $request): void
    {
        app(MfaSettingsThrottle::class)->hit($request);
    }

    private function assertFreshSession(Request $request, User $user): void
    {
        $version = $request->session()->get('auth.version');
        if (! is_int($version) || $version !== $user->auth_version) {
            throw new AuthenticationException('Unauthenticated.');
        }
    }

    private function assertPassword(string $password, User $user): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Пароль указан неверно.']);
        }
    }

    private function assertMember(Request $request, bool $lock = false): string
    {
        $org = app(Cabinet::class)->organizationId();
        $organization = Organization::whereKey($org)->where('status', 'active');
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $org)->where('user_id', $request->user()->id)->where('status', 'active');
        if ($lock) {
            $organization->sharedLock();
            $membership->sharedLock();
        }
        abort_unless($organization->first(), 403);
        abort_unless($membership->first(), 403);

        return $org;
    }

    public function cancel(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $this->withinActive($request, static fn () => null);
        $request->session()->forget('auth.mfa_setup');
        Inertia::clearHistory();

        return redirect()->route('profile.security');
    }

    private function withinActive(#[\SensitiveParameter] Request $request, \Closure $action): mixed
    {
        try {
            return app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $action): mixed {
                $this->assertMember($request, true);

                return $action();
            });
        } catch (ValidationException|AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \RuntimeException('MFA settings processing failed.');
        }
    }
}
