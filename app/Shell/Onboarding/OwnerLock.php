<?php

namespace App\Shell\Onboarding;

use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;

final class OwnerLock
{
    public static function acquire(TenantContext $context): Organization
    {
        $org = Organization::whereKey($context->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $org->id)
            ->where('user_id', $context->actorId)->where('status', 'active')->where('role', 'owner')->lockForUpdate()->first();
        abort_unless($member && $org->owner_user_id === $context->actorId, 403);

        return $org;
    }
}
