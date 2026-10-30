<?php

namespace App\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Closure;
use LogicException;

final class TenantContextRunner
{
    /** Trusted server-side boundary. Jobs must call this after restoring persisted IDs. */
    public function run(TenantContext $context, Closure $callback): mixed
    {
        $organizationId = $context->requireOrganizationId();
        if (! Organization::query()->whereKey($organizationId)->where('status', 'active')->exists()) {
            throw new LogicException('Active organization is required.');
        }
        if ($context->brandId !== null && ! Brand::withoutGlobalScopes()->whereKey($context->brandId)->where('organization_id', $organizationId)->where('status', 'active')->exists()) {
            throw new LogicException('Active brand must belong to the organization.');
        }
        if ($context->actorId !== null && ! User::query()->whereKey($context->actorId)->exists()) {
            throw new LogicException('Actor must exist.');
        }
        if ($context->membershipId !== null) {
            $membership = Membership::withoutGlobalScopes()->whereKey($context->membershipId)
                ->where('organization_id', $organizationId)->where('user_id', $context->actorId)->where('status', 'active')->first();
            if ($context->actorId === null || ! $membership || ($context->brandId !== null && ! $membership->permitsBrand($context->brandId))) {
                throw new LogicException('Membership must match the actor and authorized brand.');
            }
        }
        $previous = app(TenantContext::class);
        app()->instance(TenantContext::class, $context);
        try {
            return $callback();
        } finally {
            app()->instance(TenantContext::class, $previous);
        }
    }
}
