<?php

namespace App\Seller\Channels\Crm;

use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Integrations\Webhooks\WebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Seller/Channels/Crm/Index', ['canManage' => $canManage, 'webhooks' => $webhooks,
            'status' => session('status')]);
    }

    public function test(Request $request, TenantContext $context, string $webhook): RedirectResponse
    {
        Gate::authorize('seller.settings.read');
        Gate::authorize('integrations.write');
        abort_unless($context->organizationId && $context->brandId && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
        $data = $request->validate(['simulate_failure' => ['sometimes', 'boolean']]);
        try {
            app(CrmFakeDelivery::class)->test($context, $webhook, (bool) ($data['simulate_failure'] ?? false));
        } catch (\LogicException) {
            abort(404);
        }

        return redirect()->route('seller.integrations.crm')->with('status', 'Тестовое событие обработано. Результат виден в журнале.');
    }
}
