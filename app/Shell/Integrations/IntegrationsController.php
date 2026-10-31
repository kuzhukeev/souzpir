<?php

namespace App\Shell\Integrations;

use App\Models\Brand;
use App\Shell\Brands\BrandAccess;
use App\Shell\Integrations\Keys\KeysController;
use App\Shell\Integrations\Webhooks\WebhookDelivery;
use App\Shell\Integrations\Webhooks\WebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class IntegrationsController
{
    public function index(): Response
    {
        Gate::authorize('integrations.read');
        $ctx = app(TenantContext::class);
        abort_unless($ctx->brandId && $ctx->organizationId, 404);
        $accounts = DB::table('channel_accounts')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)->orderByDesc('updated_at')->get();
        $items = [
            ['telegram', 'Telegram', 'Переписка с клиентами в Telegram.', 'seller.channels.telegram'],
            ['widget', 'Виджет', 'Общение с клиентами на сайте.', 'seller.channels.widget'],
            ['api', 'API и вебхуки', 'Ключи доступа и события для интеграций.', 'integrations.api'],
        ];
        $connections = [];
        foreach ($items as [$type, $title, $description, $route]) {
            $account = $accounts->firstWhere('type', $type);
            $connections[] = ['id' => $type, 'title' => $title, 'description' => $description,
                'status' => $account?->status ?? 'disconnected', 'connected_at' => $account?->status === 'active' ? $account->created_at : null,
                'href' => Route::has($route) ? route($route) : null];
        }
        $connections[] = ['id' => 'notifications', 'title' => 'Уведомления компании', 'description' => 'Telegram и почта для сообщений команды.', 'status' => 'disconnected', 'connected_at' => null, 'href' => route('integrations.notifications')];
        foreach (['WhatsApp', 'VK', 'amoCRM', 'Bitrix24'] as $title) {
            $connections[] = ['id' => strtolower($title), 'title' => $title, 'description' => 'Интеграция появится позже.', 'status' => 'soon', 'connected_at' => null, 'href' => null];
        }

        return Inertia::render('Shell/Integrations/Index', compact('connections'));
    }

    public function api(Request $request, WebhookService $webhooks): Response
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);

        return Inertia::render('Shell/Integrations/Api', [
            'keys' => KeysController::listing($context),
            'webhooks' => $webhooks->forBrand($context),
            'webhookEvents' => WebhookService::eventOptions(),
            'permissions' => ['manage' => true],
            'apiKeyRuntimeAvailable' => false,
            'secureSecretTransport' => SecretTransport::allows($request),
            'secretStorageReady' => SecretSessionStore::ready(),
            'webhookDeliveryAvailable' => WebhookDelivery::available(),
            'integration_api_key_available' => $this->keepSecretForPage($request, $context, 'integration_api_key'),
            'integration_webhook_secret_available' => $this->keepSecretForPage($request, $context, 'webhook_secret'),
        ]);
    }

    /** Return a one-time value separately from Inertia props and browser history. */
    public function secret(Request $request, BrandAccess $access, SecretEnvelope $envelope): JsonResponse
    {
        SecretTransport::assertAllowed($request);
        SecretSessionStore::assertReady();
        SecretSessionStore::assertIndependentCommit();
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId && $context->actorId, 404);
        $data = $request->validate(['type' => ['required', Rule::in(['api_key', 'webhook_secret'])]]);
        $key = $data['type'] === 'api_key' ? 'integration_api_key' : 'webhook_secret';

        // The shared service locks session, user, organization, membership and brand in order.
        $value = $access->change($request, $context->brandId, function (Brand $brand, TenantContext $locked) use ($request, $key, $envelope): string {
            Gate::authorize('integrations.write');
            abort_unless($request->session()->get('brand_id') === $brand->id, 404);
            $stored = $request->session()->pull($key);
            $payload = $envelope->open($request, $locked, $key, $stored);
            abort_unless($payload !== null, 404);
            $claimed = DB::table('integration_secret_receipts')->where('nonce_hash', $payload['nonce_hash'])
                ->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);
            abort_unless($claimed === 1, 404);

            return $payload['value'];
        });

        return response()->json(['value' => $value])->header('Cache-Control', 'private, no-store');
    }

    private function keepSecretForPage(Request $request, TenantContext $context, string $key): bool
    {
        $stored = $request->session()->get($key);
        $payload = app(SecretEnvelope::class)->open($request, $context, $key, $stored);
        if ($payload === null || ! DB::table('integration_secret_receipts')->where('nonce_hash', $payload['nonce_hash'])
            ->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
            $request->session()->forget($key);

            return false;
        }
        $request->session()->keep($key);

        return true;
    }

    public function notifications(): Response
    {
        Gate::authorize('integrations.write');

        return Inertia::render('Shell/Integrations/Notifications', [
            'telegram_configured' => false, 'telegram_mask' => null, 'telegram_available' => false,
            'report_emails' => [], 'permissions' => ['manage' => false],
        ]);
    }
}
