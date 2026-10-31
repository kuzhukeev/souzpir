<?php

namespace App\Vision\Core;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Tenant
{
    /** @return array{organization_id: string, brand_id: string} */
    public function current(): array
    {
        $tenant = app(TenantContext::class);
        if ($tenant->organizationId === null || $tenant->brandId === null
            || ! DB::table('brands')->where('id', $tenant->brandId)
                ->where('organization_id', $tenant->organizationId)->where('status', 'active')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('organizations')
                    ->whereColumn('organizations.id', 'brands.organization_id')->where('organizations.status', 'active'))
                ->exists()) {
            throw new LogicException('An active organization and brand are required.');
        }

        return ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
    }
}
