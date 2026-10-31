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
}
