<?php

namespace App\Shell\Auth;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileSettingsAvailability;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class MfaChallenge
{
    /** Resolve one challenge atomically against account version and replay state. */
    public function verify(array $pending, ?array $safeCode): ?User
    {
        $recoveryReady = app(ProfileSettingsAvailability::class)->ready();
        $limitReady = app(ProfileSettingsAvailability::class)->columnsExist('mfa_challenge_limits', ['user_id', 'failures', 'expires_at']);

        try {
            return $this->verifyMode($pending, $safeCode, $recoveryReady, $limitReady);
        } catch (QueryException $error) {
            // A concurrent schema change may invalidate an earlier readiness
            // check. Retry only TOTP with the existing limiter after rollback.
            if (in_array($error->errorInfo[0] ?? null, ['42P01', '42703'], true)
                && preg_match('/\bmfa_(?:challenge_limits|recovery_codes)\b/', $error->getSql())) {
                return $this->verifyMode($pending, $safeCode, false, false);
            }
            throw $error;
        }
    }

    private function verifyMode(array $pending, ?array $safeCode, bool $recoveryReady, bool $limitReady): ?User
    {
        return DB::transaction(function () use ($pending, $safeCode, $recoveryReady, $limitReady): ?User {
            $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
            if (! $user || ! $user->mfa_confirmed_at || ! $user->mfa_secret || $user->auth_version !== $pending['version']
                || $pending['expires'] <= now()->timestamp) {
                return null;
            }
            // The user-row lock serializes both the limit and the code consumption,
            // even when a cache lease expires or different workers race.
            // This shared legacy key is always used, including while the schema
            // and feature flag change; DB state adds durability when available.
            $rateKey = 'auth.mfa:'.hash('sha256', $user->id);
            if (RateLimiter::tooManyAttempts($rateKey, 5)) {
                return null;
            }
            $limit = $limitReady ? DB::table('mfa_challenge_limits')->where('user_id', $user->id)->first() : null;
            if ($limit && strtotime($limit->expires_at) > now()->timestamp && $limit->failures >= 5) {
                return null;
            }
            if ($limit && strtotime($limit->expires_at) <= now()->timestamp) {
                DB::table('mfa_challenge_limits')->where('user_id', $user->id)->delete();
                $limit = null;
            }
            $valid = false;
            if (is_array($safeCode) && is_string($safeCode['digest'] ?? null) && ($safeCode['kind'] ?? null) === 'totp') {
                $counter = MfaCode::matchTotp($user->mfa_secret, $safeCode['digest'], $user->mfa_last_counter);
                if ($counter !== null) {
                    $user->forceFill(['mfa_last_counter' => $counter])->save();
                    $valid = true;
                }
            } elseif ($recoveryReady && is_array($safeCode) && is_string($safeCode['digest'] ?? null) && ($safeCode['kind'] ?? null) === 'recovery') {
                $used = DB::table('mfa_recovery_codes')->where('user_id', $user->id)->where('code_hash', $safeCode['digest'])
                    ->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
                $valid = $used === 1;
                if ($valid) {
                    app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $user->id),
                        new AuditEntry('account.mfa.recovery_code_used', 'user', $user->id));
                }
            }
            if (! $valid) {
                RateLimiter::hit($rateKey, 900);
                if ($limitReady && $limit) {
                    DB::table('mfa_challenge_limits')->where('user_id', $user->id)->increment('failures');
                } elseif ($limitReady) {
                    DB::table('mfa_challenge_limits')->insert(['user_id' => $user->id, 'failures' => 1, 'expires_at' => now()->addMinutes(15)]);
                }

                return null;
            }
            if ($limitReady) {
                DB::table('mfa_challenge_limits')->where('user_id', $user->id)->delete();
            }
            RateLimiter::clear($rateKey);

            return $user;
        });
    }
}
