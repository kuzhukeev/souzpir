<?php

namespace App\Seller\Settings\Limits;

use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;

/** Shared entitlement source for web requests, outbox processing and delivery workers. */
final class SellerModuleAccess
{
    public function enabled(string $organization, string $brand): bool
    {
        return app(ModuleEntitlements::class)->enabled(new TenantContext($organization, $brand, source: 'job'), 'seller');
    }
}
