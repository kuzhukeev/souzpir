<?php

namespace App\Shell\Auth;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class MfaChallenge
{
    /** Resolve one challenge atomically against account version and replay state. */
    public function verify(array $pending, ?array $safeCode): ?User
    {
        return DB::transaction(function () use ($pending, $safeCode): ?User {
            $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
            if (! $user || ! $user->mfa_confirmed_at || ! $user->mfa_secret || $user->auth_version !== $pending['version']
                || $pending['expires'] <= now()->timestamp) {
                return null;
            }
            // The user-row lock serializes both the limit and the code consumption,
            // even when a cache lease expires or different workers race.
            $limit = DB::table('mfa_challenge_limits')->where('user_id', $user->id)->first();
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
            } elseif (is_array($safeCode) && is_string($safeCode['digest'] ?? null) && ($safeCode['kind'] ?? null) === 'recovery') {
                $used = DB::table('mfa_recovery_codes')->where('user_id', $user->id)->where('code_hash', $safeCode['digest'])
                    ->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
                $valid = $used === 1;
                if ($valid) {
                    app(AuditWriter::class)->record(new TenantContext(app(Cabinet::class)->organizationId(), actorId: $user->id),
                        new AuditEntry('account.mfa.recovery_code_used', 'user', $user->id));
                }
            }
            if (! $valid) {
                if ($limit) {
                    DB::table('mfa_challenge_limits')->where('user_id', $user->id)->increment('failures');
                } else {
                    DB::table('mfa_challenge_limits')->insert(['user_id' => $user->id, 'failures' => 1, 'expires_at' => now()->addMinutes(15)]);
                }

                return null;
            }
            DB::table('mfa_challenge_limits')->where('user_id', $user->id)->delete();

            return $user;
        });
    }
}
