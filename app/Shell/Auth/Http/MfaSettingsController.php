<?php

namespace App\Shell\Auth\Http;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\Totp;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MfaSettingsController
{
    public function start(Request $request, Totp $totp): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'current_password']]);
        $this->assertMember($request);
        $setup = DB::transaction(function () use ($request, $data, $totp): array {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshSession($request, $user);
            $this->assertPassword($data['password'], $user);
            if ($user->mfa_confirmed_at) {
                throw ValidationException::withMessages(['password' => 'Двухфакторная проверка уже включена.']);
            }

            return ['user_id' => $user->id, 'version' => $user->auth_version, 'secret' => $totp->generateSecret(), 'expires' => now()->addMinutes(10)->timestamp];
        });
        $request->session()->put('auth.mfa_setup', $setup);

        return redirect()->route('mfa.setup');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $this->assertMember($request);
        $setup = $this->setup($request);
        if (! $setup) {
            return redirect()->route('auth.ready');
        }

        return Inertia::render('Shell/Auth/MfaSetup', ['secret' => $setup['secret']]);
    }

    public function confirm(Request $request, Totp $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $org = $this->assertMember($request);
        $setup = $this->setup($request);
        if (! $setup) {
            return redirect()->route('auth.ready');
        }
        $counter = $totp->matchingCounter($setup['secret'], (string) $data['code'], now()->timestamp, null);
        if ($counter === null) {
            throw ValidationException::withMessages(['code' => 'Неверный код.']);
        }
        $version = DB::transaction(function () use ($request, $org, $setup, $counter): int {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshSession($request, $user);
            if ($setup['version'] !== $user->auth_version) {
                throw new AuthenticationException('Unauthenticated.');
            }
            if ($user->mfa_confirmed_at) {
                throw ValidationException::withMessages(['code' => 'Двухфакторная проверка уже включена.']);
            }
            $user->forceFill(['mfa_secret' => $setup['secret'], 'mfa_confirmed_at' => now(), 'mfa_last_counter' => $counter, 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            app(AuditWriter::class)->record(new TenantContext($org, actorId: $user->id), new AuditEntry('account.mfa.enabled', 'user', $user->id));

            return $user->auth_version;
        });
        $request->session()->forget('auth.mfa_setup');
        $request->session()->put('auth.version', $version);

        return redirect()->route('auth.ready')->with('status', 'Двухфакторная проверка включена.');
    }

    public function disable(Request $request, Totp $totp): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'current_password'], 'code' => ['required', 'digits:6']]);
        $org = $this->assertMember($request);
        $version = DB::transaction(function () use ($request, $data, $totp, $org): int {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshSession($request, $user);
            $this->assertPassword($data['password'], $user);
            $counter = $user->mfa_secret ? $totp->matchingCounter($user->mfa_secret, (string) $data['code'], now()->timestamp, $user->mfa_last_counter) : null;
            if (! $user->mfa_confirmed_at || $counter === null) {
                throw ValidationException::withMessages(['code' => 'Неверный код.']);
            }
            $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_last_counter' => null, 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            app(AuditWriter::class)->record(new TenantContext($org, actorId: $user->id), new AuditEntry('account.mfa.disabled', 'user', $user->id));

            return $user->auth_version;
        });
        $request->session()->put('auth.version', $version);

        return redirect()->route('auth.ready')->with('status', 'Двухфакторная проверка отключена.');
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

    private function assertMember(Request $request): string
    {
        $org = app(Cabinet::class)->organizationId();
        abort_unless(Organization::whereKey($org)->where('status', 'active')->exists(), 403);
        abort_unless(Membership::withoutGlobalScopes()->where('organization_id', $org)->where('user_id', $request->user()->id)->where('status', 'active')->exists(), 403);

        return $org;
    }

    private function setup(Request $request): ?array
    {
        $setup = $request->session()->get('auth.mfa_setup');
        if (! is_array($setup) || ($setup['user_id'] ?? null) !== $request->user()->id || ! is_int($setup['version'] ?? null) || ! is_string($setup['secret'] ?? null) || ! is_int($setup['expires'] ?? null) || $setup['expires'] <= now()->timestamp) {
            $request->session()->forget('auth.mfa_setup');

            return null;
        }

        return $setup;
    }
}
