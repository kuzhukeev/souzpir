<?php

namespace App\Seller\Channels\Crm;

use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CrmController
{
    public function __invoke(TenantContext $context): Response
    {
        Gate::authorize('seller.settings.read');
        abort_unless($context->organizationId && $context->brandId && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);

        return Inertia::render('Seller/Channels/Crm/Index');
    }
}
