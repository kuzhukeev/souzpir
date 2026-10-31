<?php

namespace App\Shell\Auth;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Recaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Server-side authorization leases; raw browser session IDs never enter the database. */
final class AuthSessionRegistry
{
    public function issue(Request $request, User $user, int $expectedVersion): void
    {
        if (! $request->user() || (string) $request->user()->getAuthIdentifier() !== (string) $user->getAuthIdentifier()) {
            throw new AuthenticationException('Unauthenticated.');
        }
        DB::transaction(function () use ($request, $user, $expectedVersion): void {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->auth_version !== $expectedVersion) {
                throw new AuthenticationException('Unauthenticated.');
            }
            $this->insert($request, $fresh, null);
        });
    }

    public function issueRemembered(Request $request, User $user): void
    {
        $guard = Auth::guard();
        $cookie = $request->cookies->get($guard->getRecallerName());
        if (! $guard->viaRemember() || ! is_string($cookie) || ! $request->user() || (string) $request->user()->getAuthIdentifier() !== (string) $user->getAuthIdentifier()) {
            throw new AuthenticationException('Unauthenticated.');
        }
        $recaller = new Recaller($cookie);
        if (! $recaller->valid() || $recaller->id() !== (string) $user->getAuthIdentifier()) {
            throw new AuthenticationException('Unauthenticated.');
        }
        DB::transaction(function () use ($request, $user, $recaller): void {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $fresh || ! is_string($fresh->getRememberToken()) || ! hash_equals($fresh->getRememberToken(), $recaller->token()) || $fresh->auth_version !== $user->auth_version) {
                throw new AuthenticationException('Unauthenticated.');
            }
            $this->insert($request, $fresh, $this->rememberHash($recaller->token()));
            $request->session()->put('auth.version', $fresh->auth_version);
        });
    }

    /** Check current session and hold its row plus the user lock until the action commits. */
    public function withinActive(Request $request, Closure $action): mixed
    {
        return DB::transaction(function () use ($request, $action): mixed {
            if (! $request->hasSession() || ! $request->user()) {
                throw new AuthenticationException('Unauthenticated.');
            }
            $row = DB::table('auth_session_registry')->where('session_hash', $this->sessionHash($request->session()->getId()))->lockForUpdate()->first();
            $user = User::whereKey($request->user()->getAuthIdentifier())->lockForUpdate()->first();
            $version = $request->session()->get('auth.version');
            if (! $row || ! $user || $row->user_id !== $user->id || $row->revoked_at !== null || now()->greaterThanOrEqualTo($row->expires_at)
                || ! is_int($version) || $version !== $user->auth_version || (int) $row->auth_version !== $user->auth_version
                || ($row->remember_fingerprint !== null && (! is_string($user->getRememberToken()) || ! hash_equals($row->remember_fingerprint, $this->rememberHash($user->getRememberToken()))))) {
                throw new AuthenticationException('Unauthenticated.');
            }
            DB::table('auth_session_registry')->where('session_hash', $row->session_hash)->update(['expires_at' => $this->expiresAt(), 'updated_at' => now()]);

            return $action();
        });
    }

    /** Invoke only within withinActive, in the same transaction as the action. */
    public function revokeCurrent(Request $request): void
    {
        if (DB::connection()->transactionLevel() < 1 || ! $request->user()) {
            throw new LogicException('Session revocation requires a locked transaction.');
        }
        $changed = DB::table('auth_session_registry')->where('session_hash', $this->sessionHash($request->session()->getId()))->where('user_id', $request->user()->getAuthIdentifier())->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new AuthenticationException('Unauthenticated.');
        }
    }

    /** Invoke after a password-confirmed MFA change, within the held session transaction. */
    public function advanceVersion(Request $request, int $version): void
    {
        if (DB::connection()->transactionLevel() < 1 || ! $request->user()) {
            throw new LogicException('Session update requires a locked transaction.');
        }
        $changed = DB::table('auth_session_registry')->where('session_hash', $this->sessionHash($request->session()->getId()))->where('user_id', $request->user()->getAuthIdentifier())->whereNull('revoked_at')->update(['auth_version' => $version, 'remember_fingerprint' => null, 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new AuthenticationException('Unauthenticated.');
        }
    }

    /** Rotate session ID while atomically retiring the previous registry entry. */
    public function rotate(Request $request): void
    {
        $this->withinActive($request, function () use ($request): void {
            $old = $this->sessionHash($request->session()->getId());
            $request->session()->regenerate(true);
            DB::table('auth_session_registry')->where('session_hash', $old)->update(['session_hash' => $this->sessionHash($request->session()->getId()), 'updated_at' => now()]);
        });
    }

    private function insert(Request $request, User $user, ?string $remember): void
    {
        if (! $request->hasSession()) {
            throw new AuthenticationException('Unauthenticated.');
        }
        DB::table('auth_session_registry')->insert(['session_hash' => $this->sessionHash($request->session()->getId()), 'user_id' => $user->id, 'auth_version' => $user->auth_version, 'remember_fingerprint' => $remember, 'expires_at' => $this->expiresAt(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sessionHash(string $sessionId): string
    {
        return hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
    }

    private function rememberHash(string $token): string
    {
        return hash_hmac('sha256', 'auth-remember:'.$token, (string) config('app.key'));
    }

    private function expiresAt(): string
    {
        return now()->addMinutes(max(1, (int) config('session.lifetime', 120)))->format('Y-m-d H:i:s.uP');
    }
}
