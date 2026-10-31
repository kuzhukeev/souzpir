<?php

namespace App\Shell\Billing;

use App\Tenancy\TenantContext;

/** Opaque binding of a browser attempt to its immutable tenant snapshot. */
final class SubmissionScope
{
    public static function for(TenantContext $tenant): ?string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '' || $tenant->organizationId === null || $tenant->actorId === null || $tenant->brandId === null) {
            return null;
        }

        return hash_hmac('sha256', 'billing.plan-request.v1|'.$tenant->organizationId.'|'.$tenant->actorId.'|'.$tenant->brandId, $key);
    }
}
