<?php

namespace App\Shell\Integrations;

use App\Shell\Integrations\Keys\KeysController;
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
            'webhookDeliveryAvailable' => config('features.E1-10.defaults.webhook_delivery', false) === true,
            'integration_api_key_available' => $this->keepSecretForPage($request, $context, 'integration_api_key'),
            'integration_webhook_secret_available' => $this->keepSecretForPage($request, $context, 'webhook_secret'),
        ]);
    }

    /** Return a one-time value separately from Inertia props and browser history. */
    public function secret(Request $request): JsonResponse
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId && $context->actorId, 404);
        $data = $request->validate(['type' => ['required', Rule::in(['api_key', 'webhook_secret'])]]);
        $key = $data['type'] === 'api_key' ? 'integration_api_key' : 'webhook_secret';
        $stored = $request->session()->pull($key);
        abort_unless($this->matchesSecret($stored, $context), 404);

        return response()->json(['value' => $stored['value']])->header('Cache-Control', 'private, no-store');
    }

    private function keepSecretForPage(Request $request, TenantContext $context, string $key): bool
    {
        $stored = $request->session()->get($key);
        if (! $this->matchesSecret($stored, $context)) {
            $request->session()->forget($key);

            return false;
        }
        $request->session()->keep($key);

        return true;
    }

    private function matchesSecret(mixed $stored, TenantContext $context): bool
    {
        return is_array($stored) && $context->organizationId !== null && $context->brandId !== null && $context->actorId !== null
            && ($stored['organization_id'] ?? null) === $context->organizationId
            && ($stored['brand_id'] ?? null) === $context->brandId
            && ($stored['actor_id'] ?? null) === $context->actorId
            && is_string($stored['value'] ?? null) && $stored['value'] !== '';
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
