<?php

namespace App\Vision\SiteAudit\Knowledge;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Rechecked before crawling and each persisted page; HTTP never holds these locks. */
final class SiteImportAccess
{
    public function lock(TenantContext $tenant): object
    {
        $current = app(TenantContext::class);
        if (DB::transactionLevel() < 1 || ! $tenant->organizationId || ! $tenant->brandId
            || ! $tenant->actorId || ! $tenant->membershipId || $tenant->source === 'public'
            || $current != $tenant) {
            throw new LogicException('Нет доступа к импорту сайта.');
        }
        $organization = DB::table('organizations')->where('id', $tenant->organizationId)
            ->where('status', 'active')->sharedLock()->first();
        $brand = DB::table('brands')->where('id', $tenant->brandId)
            ->where('organization_id', $tenant->organizationId)->where('status', 'active')->sharedLock()->first();
        $membership = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)
            ->where('organization_id', $tenant->organizationId)->where('user_id', $tenant->actorId)
            ->where('status', 'active')->sharedLock()->first();
        $user = User::query()->find($tenant->actorId);
        if (! $organization || ! $brand || ! $membership || ! $membership->permitsBrand($tenant->brandId)
            || ! $user || ! Gate::forUser($user)->allows('vision.settings.write')) {
            throw new LogicException('Нет доступа к импорту сайта.');
        }

        return $brand;
    }
}
