<?php

namespace App\Shell\MemberActivity;

use App\Shell\Auth\SessionRegistered;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Optional telemetry must not poison or interrupt the surrounding login transaction. */
final class RecordCompletedLogin
{
    public function handle(SessionRegistered $event): void
    {
        try {
            DB::transaction(static function () use ($event): void {
                // Current Auth sets a fingerprint only for issueRemembered, not for
                // a completed interactive login with the remember option enabled.
                $interactive = DB::table('auth_session_registry')->where('session_hash', $event->sessionHash)
                    ->where('user_id', $event->userId)->whereNull('remember_fingerprint')->exists();
                if (! $interactive) {
                    return;
                }
                DB::statement('INSERT INTO member_last_logins (user_id, last_login_at) VALUES (?, ?) ON CONFLICT (user_id) DO UPDATE SET last_login_at = GREATEST(member_last_logins.last_login_at, EXCLUDED.last_login_at)', [
                    $event->userId, now('UTC')->format('Y-m-d H:i:s.uP'),
                ]);
            });
        } catch (Throwable) {
            // Missing/partial schema or a write failure must roll back the savepoint,
            // not the successful login. No session/IP/user-agent data is logged.
        }
    }
}
