<?php

namespace App\Seller\Channels\Crm;

use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Integrations\Webhooks\WebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

        foreach ($webhooks as &$hook) {
            $saved = DB::table('crm_webhook_mappings')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('webhook_id', $hook['id'])->value('fields');
            $hook['mappings'] = $saved ? json_decode($saved, true, 32, JSON_THROW_ON_ERROR) : [];
        }
        unset($hook);

        return Inertia::render('Seller/Channels/Crm/Index', ['canManage' => $canManage, 'webhooks' => $webhooks,
            'status' => session('status')]);
    }

    public function mappings(Request $request, TenantContext $context, string $webhook): RedirectResponse
    {
        Gate::authorize('seller.settings.read');
        Gate::authorize('integrations.write');
        abort_unless($context->organizationId && $context->brandId && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
        $data = $request->validate([
            'fields' => ['required', 'array', 'size:3'],
            'fields.name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'distinct'],
            'fields.phone' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'distinct'],
            'fields.email' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'distinct'],
        ]);
        if (count(array_unique($data['fields'])) !== 3 || array_intersect($data['fields'], ['event_id', 'event', 'occurred_at', 'brand_id', 'reason', 'conversation_id', 'contact_id', 'task_id'])) {
            throw ValidationException::withMessages(['fields' => 'Названия полей должны быть уникальными и не совпадать со служебными.']);
        }
        $hook = DB::table('webhooks')->where('id', $webhook)->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->first();
        abort_unless($hook && array_intersect(['seller.contact.captured', 'seller.conversation.handed_off'], json_decode($hook->events, true, 32, JSON_THROW_ON_ERROR)), 404);
        DB::table('crm_webhook_mappings')->updateOrInsert(
            ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'webhook_id' => $webhook],
            ['fields' => json_encode($data['fields'], JSON_THROW_ON_ERROR), 'updated_at' => now()]
        );

        return redirect()->route('seller.integrations.crm')->with('status', 'Сопоставление сохранено.');
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
