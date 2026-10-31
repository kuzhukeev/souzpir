<?php

namespace App\Seller\Conversations;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Call inside a transaction; lock in the same order as E3-01. */
final class ActiveTenantLock
{
    public static function acquire(TenantContext $context): void
    {
        if ($context->organizationId === null || $context->brandId === null) {
            throw new LogicException('Active organization and brand are required.');
        }
        $organization = DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first();
        if (! $organization) {
            throw new LogicException('Active organization is required.');
        }
        $brand = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first();
        if (! $brand) {
            throw new LogicException('Active brand is required.');
        }
    }
}
