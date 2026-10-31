<?php

namespace App\Shell\Profile\Security;

use App\Shell\Auth\SessionRegistered;
use Illuminate\Support\Facades\DB;

/** Runs in the issuing transaction. Failure must not leave an unauditable partial login. */
final class CaptureSessionDetails
{
    public function handle(SessionRegistered $event): void
    {
        $ip = $event->ipAddress !== null && filter_var($event->ipAddress, FILTER_VALIDATE_IP) !== false ? $event->ipAddress : null;
        $agent = $event->userAgent === null ? null : mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $event->userAgent) ?? '', 0, 500);
        DB::table('profile_session_details')->insert([
            'session_hash' => $event->sessionHash,
            'user_id' => $event->userId,
            'ip_address' => $ip,
            'user_agent' => $agent === '' ? null : $agent,
            'created_at' => now(),
        ]);
    }
}
