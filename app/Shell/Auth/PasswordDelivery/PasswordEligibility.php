<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Call after locking the user; locks remain held through the caller's transaction. */
final class PasswordEligibility
{
    public function lock(?User $user): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Eligibility requires a transaction.');
        }
        if (! $user) {
            return false;
        }
        $orgId = app(Cabinet::class)->organizationId();
        $org = Organization::whereKey($orgId)->lockForUpdate()->first();
        if (! $org || $org->status !== 'active') {
            return false;
        }
        $invitations = DB::table('invitations')->where('organization_id', $orgId)->where('email', $user->email)->orderBy('id')->lockForUpdate()->get();
        $membership = DB::table('memberships')->where('organization_id', $orgId)->where('user_id', $user->id)->lockForUpdate()->first();

        return ($membership && $membership->status === 'active') || $invitations->contains(fn ($invitation) => $invitation->accepted_at === null && $invitation->revoked_at === null && CarbonImmutable::parse($invitation->expires_at)->greaterThan(now()));
    }
}
