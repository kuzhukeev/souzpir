<?php

namespace App\Shell\Auth;

use App\Models\Membership;
use App\Shell\Team\Models\Invitation;
use Illuminate\Support\Facades\DB;

final class EligibleAccount
{
    public function includes(string $email): bool
    {
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! $userId) {
            return false;
        }
        if (Membership::withoutGlobalScopes()->where('user_id', $userId)->where('status', 'active')->exists()) {
            return true;
        }

        return Invitation::withoutGlobalScopes()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
    }
}
