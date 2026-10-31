<?php

namespace App\Shell\Profile\Security;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\MfaSetupState;
use App\Shell\Auth\SecureAccountContext;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SecuritySettings
{
    public function view(#[\SensitiveParameter] Request $request): array
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context, object $brand) use ($request): array {
            $current = $this->currentHash($request);
            $remember = $user->getRememberToken();
            $fingerprint = is_string($remember) ? hash_hmac('sha256', 'auth-remember:'.$remember, (string) config('app.key')) : null;
            $sessions = DB::table('auth_session_registry as leases')
                ->leftJoin('profile_session_details as details', function ($join): void {
                    $join->on('details.session_hash', '=', 'leases.session_hash')->on('details.user_id', '=', 'leases.user_id');
                })
                ->where('leases.user_id', $user->id)->where('leases.auth_version', $user->auth_version)
                ->whereNull('leases.revoked_at')->where('leases.expires_at', '>', now())
                ->where(function ($query) use ($fingerprint): void {
                    $query->whereNull('leases.remember_fingerprint');
                    if ($fingerprint !== null) {
                        $query->orWhere('leases.remember_fingerprint', $fingerprint);
                    }
                })
                ->orderByDesc('leases.updated_at')
                ->get(['leases.session_hash', 'leases.updated_at', 'leases.expires_at', 'details.ip_address', 'details.user_agent'])
                ->map(fn (object $row): array => [
                    'id' => $this->publicId($row->session_hash),
                    'device' => $this->device($row->user_agent),
                    'ip_address' => $row->ip_address,
                    'last_active_at' => Carbon::parse($row->updated_at)->toIso8601String(),
                    'expires_at' => Carbon::parse($row->expires_at)->toIso8601String(),
                    'is_current' => hash_equals($current, $row->session_hash),
                ])->sortByDesc('is_current')->values()->all();
            $pending = SecureAccountContext::allows($request) ? app(MfaSetupState::class)->pending($request) : null;
            $issuer = (string) config('app.name', 'souzpir');

            return [
                'timezone' => DB::table('profile_preferences')->where('user_id', $user->id)->value('timezone') ?? $brand->timezone,
                'mfa_enabled' => $user->mfa_confirmed_at !== null,
                'sessions' => $sessions,
                'setup' => $pending === null ? null : [
                    'secret' => $pending['secret'],
                    'otpauth_url' => 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?'.http_build_query([
                        'secret' => $pending['secret'], 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
                    ], '', '&', PHP_QUERY_RFC3986),
                    'expires_at' => Carbon::createFromTimestamp($pending['expires'])->toIso8601String(),
                ],
            ];
        });
    }

    public function password(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $data): void
    {
        try {
            $changed = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($request, $data): User {
                if (! Hash::check($data['current_password'], $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
                }
                $user->forceFill([
                    'password' => $data['password'], 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1,
                ])->save();
                app(AuthSessionRegistry::class)->advanceVersion($request, $user->auth_version);
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                app(AuditWriter::class)->record($context, new AuditEntry('account.password.changed', 'user', $user->id));

                return $user;
            });
        } catch (ValidationException|AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            // Do not carry query bindings or a previous exception with credentials into logs.
            throw new \RuntimeException('Password change processing failed.');
        }
        Auth::guard()->setUser($changed);
        $request->session()->put('auth.version', $changed->auth_version);
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), Auth::guard()->hashPasswordForCookie($changed->password));
        $request->session()->forget('auth.mfa_setup');
    }

    public function revoke(#[\SensitiveParameter] Request $request, string $id): void
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $id), 404);
        $this->withSessionLocks($request, function (User $user, TenantContext $context) use ($request, $id): void {
            $target = DB::table('auth_session_registry')->where('user_id', $user->id)
                ->where('auth_version', $user->auth_version)->whereNull('revoked_at')->where('expires_at', '>', now())
                ->get()->first(fn (object $row): bool => hash_equals($this->publicId($row->session_hash), $id));
            abort_unless($target, 404);
            if (hash_equals($this->currentHash($request), $target->session_hash)) {
                throw ValidationException::withMessages(['sessions' => 'Для текущего устройства используйте кнопку «Выйти».']);
            }
            DB::table('auth_session_registry')->where('session_hash', $target->session_hash)->update(['revoked_at' => now(), 'updated_at' => now()]);
            // Laravel's remember token is shared across devices. Rotate it to prevent a revoked
            // browser from recreating a lease, while preserving all remaining live leases.
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            DB::table('auth_session_registry')->where('user_id', $user->id)->whereNull('revoked_at')
                ->update(['remember_fingerprint' => null]);
            app(AuditWriter::class)->record($context, new AuditEntry('account.session.revoked', 'user', $user->id, after: ['session' => $id]));
        });
    }

    public function revokeOthers(#[\SensitiveParameter] Request $request): void
    {
        $version = $this->withSessionLocks($request, function (User $user, TenantContext $context) use ($request): int {
            $current = $this->currentHash($request);
            $count = DB::table('auth_session_registry')->where('user_id', $user->id)->where('session_hash', '!=', $current)
                ->whereNull('revoked_at')->where('expires_at', '>', now())->where('auth_version', $user->auth_version)->count();
            DB::table('auth_session_registry')->where('user_id', $user->id)->where('session_hash', '!=', $current)
                ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $user->forceFill(['remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
            app(AuthSessionRegistry::class)->advanceVersion($request, $user->auth_version);
            app(AuditWriter::class)->record($context, new AuditEntry('account.sessions.revoked', 'user', $user->id, after: ['count' => $count]));

            return $user->auth_version;
        });
        $request->session()->put('auth.version', $version);
        $request->session()->forget('auth.mfa_setup');
    }

    private function withSessionLocks(#[\SensitiveParameter] Request $request, \Closure $action): mixed
    {
        return DB::transaction(function () use ($request, $action): mixed {
            // Canonical order before taking the user lock prevents two devices revoking
            // one another from taking current→user→other-session locks in opposite order.
            DB::table('auth_session_registry')->where('user_id', $request->user()?->id)->orderBy('session_hash')->lockForUpdate()->get();

            return app(ProfileAccess::class)->run($request, $action);
        }, 3);
    }

    public function publicId(string $sessionHash): string
    {
        return hash_hmac('sha256', 'profile-session:'.$sessionHash, (string) config('app.key'));
    }

    private function currentHash(#[\SensitiveParameter] Request $request): string
    {
        return hash_hmac('sha256', 'auth-session:'.$request->session()->getId(), (string) config('app.key'));
    }

    private function device(?string $agent): string
    {
        $agent = mb_strtolower($agent ?? '');
        $browser = match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'firefox/') || str_contains($agent, 'fxios/') => 'Firefox',
            str_contains($agent, 'chrome/') || str_contains($agent, 'crios/') => 'Chrome',
            str_contains($agent, 'safari/') => 'Safari',
            default => 'Браузер не определён',
        };
        $system = match (true) {
            str_contains($agent, 'iphone') || str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => null,
        };

        return $browser.($system === null ? '' : ' · '.$system);
    }
}
