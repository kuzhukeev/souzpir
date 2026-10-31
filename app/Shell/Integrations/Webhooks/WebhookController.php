<?php

namespace App\Shell\Integrations\Webhooks;

use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WebhookController
{
    public function store(Request $request, WebhookService $service): RedirectResponse
    {
        $secret = $service->store($request);

        $ctx = app(TenantContext::class);

        return redirect('/integrations/api')->with('webhook_secret', ['organization_id' => $ctx->organizationId, 'brand_id' => $ctx->brandId, 'actor_id' => $ctx->actorId, 'value' => $secret]);
    }

    public function test(Request $request, string $webhook, WebhookService $service): RedirectResponse
    {
        $service->test($request, $webhook);

        return redirect('/integrations/api');
    }
}
