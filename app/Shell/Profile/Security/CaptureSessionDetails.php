<?php

namespace App\Shell\Profile\Security;

use App\Shell\Auth\SessionRegistered;
use App\Shell\Profile\ProfileSettingsAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Runs in the issuing transaction. Failure must not leave an unauditable partial login. */
final class CaptureSessionDetails
{
    public function handle(SessionRegistered $event): void
    {
        if (! app(ProfileSettingsAvailability::class)->ready()) {
            return;
        }
        $ip = $event->ipAddress !== null && filter_var($event->ipAddress, FILTER_VALIDATE_IP) !== false ? $event->ipAddress : null;
        $agent = $event->userAgent === null ? null : mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $event->userAgent) ?? '', 0, 500);
        try {
            DB::transaction(static function () use ($event, $ip, $agent): void {
                DB::table('profile_session_details')->insert([
                    'session_hash' => $event->sessionHash,
                    'user_id' => $event->userId,
                    'ip_address' => $ip,
                    'user_agent' => $agent === '' ? null : $agent,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $error) {
            // A schema transition can remove the table after readiness was
            // checked. The savepoint preserves the outer login transaction.
            if (! in_array($error->errorInfo[0] ?? null, ['42P01', '42703'], true)) {
                throw $error;
            }
        }
    }
}
