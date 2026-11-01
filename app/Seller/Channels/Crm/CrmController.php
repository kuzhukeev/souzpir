<?php

namespace App\Seller\Channels\Crm;

use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Integrations\Webhooks\WebhookService;
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

        $canManage = Gate::allows('integrations.write');
        $events = ['seller.contact.captured', 'seller.conversation.handed_off'];
        $webhooks = $canManage ? array_values(array_filter(app(WebhookService::class)->forBrand($context),
            fn (array $hook): bool => array_intersect($events, $hook['events']) !== [])) : [];

        return Inertia::render('Seller/Channels/Crm/Index', ['canManage' => $canManage, 'webhooks' => $webhooks]);
    }
}
