<?php

namespace App\Vision\KnowledgeGrants;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class GrantAccess
{
    /** Locks organization → brand → membership; returns the current brand row. */
    public function lock(TenantContext $tenant, bool $write, ?string $ability = null): object
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A transaction is required.');
        }
        $current = app(TenantContext::class);
        if (! $tenant->organizationId || ! $tenant->brandId || $tenant->source === 'public'
            || $current->organizationId !== $tenant->organizationId || $current->brandId !== $tenant->brandId
            || $current->actorId !== $tenant->actorId || $current->membershipId !== $tenant->membershipId
            || $current->source !== $tenant->source) {
            throw new LogicException('Knowledge grant access denied.');
        }
        $orgQuery = DB::table('organizations')->where('id', $tenant->organizationId)->where('status', 'active');
        $brandQuery = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->where('status', 'active');
        $org = ($write ? $orgQuery->lockForUpdate() : $orgQuery->sharedLock())->first();
        $brand = ($write ? $brandQuery->lockForUpdate() : $brandQuery->sharedLock())->first();
        if (! $org || ! $brand) {
            throw new LogicException('Knowledge grant access denied.');
        }
        $serviceJob = $tenant->source === 'job' && $tenant->actorId === null && $tenant->membershipId === null;
        if ($write || ! $serviceJob) {
            if (! $tenant->actorId || ! $tenant->membershipId) {
                throw new LogicException('Knowledge grant access denied.');
            }
            $query = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)
                ->where('organization_id', $tenant->organizationId)->where('user_id', $tenant->actorId)->where('status', 'active');
            $membership = ($write ? $query->lockForUpdate() : $query->sharedLock())->first();
            if (! $membership || ! $membership->permitsBrand($tenant->brandId)) {
                throw new LogicException('Knowledge grant access denied.');
            }
            if ($ability !== null) {
                $user = User::query()->find($tenant->actorId);
                if (! $user || ! Gate::forUser($user)->allows($ability)) {
                    throw new LogicException('Knowledge grant access denied.');
                }
            }
        }

        return $brand;
    }
}
