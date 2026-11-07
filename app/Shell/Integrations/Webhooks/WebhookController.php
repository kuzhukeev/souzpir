<?php

namespace App\Shell\Integrations\Webhooks;

use App\Shell\Integrations\SecretSessionStore;
use App\Shell\Integrations\SecretTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WebhookController
{
    public function store(Request $request, WebhookService $service): RedirectResponse
    {
        SecretTransport::assertAllowed($request);
        SecretSessionStore::assertReady();
        SecretSessionStore::assertIndependentCommit();
        $sealed = $service->store($request);

        return redirect('/integrations/api')->with('webhook_secret', $sealed);
    }

    public function test(Request $request, string $webhook, WebhookService $service): RedirectResponse
    {
        $service->test($request, $webhook);

        return redirect('/integrations/api');
    }

    public function configure(Request $request, WebhookService $service): RedirectResponse
    {
        abort_unless($request->attributes->get('webhook_transport_secure') === true, 403, SecretTransport::MESSAGE);
        $service->configure($request);

        return redirect(($request->attributes->get('webhook_configuration')['return_to'] ?? null) === 'crm' ? '/seller/integrations/crm' : '/integrations/api');
    }

    public function fakeTest(Request $request, string $webhook, WebhookService $service): RedirectResponse
    {
        $service->fakeTest($request, $webhook);

        return redirect('/integrations/api');
    }
}
