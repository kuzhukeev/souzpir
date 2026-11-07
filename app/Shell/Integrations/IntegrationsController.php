<?php

namespace App\Shell\Integrations;

use App\Models\Brand;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Brands\BrandAccess;
use App\Shell\Integrations\CompanyNotifications\CompanyNotificationSettings;
use App\Shell\Integrations\Keys\KeysController;
use App\Shell\Integrations\Webhooks\WebhookDelivery;
use App\Shell\Integrations\Webhooks\WebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use SensitiveParameter;

final class IntegrationsController
{
    private function externalDeliveryEnabled(): bool
    {
        return (bool) config('features.E0-04.defaults.external_delivery') && config('runtime.notification_driver') !== 'fake';
    }

    public function index(): Response
    {
        Gate::authorize('integrations.read');
        $ctx = app(TenantContext::class);
        abort_unless($ctx->brandId && $ctx->organizationId, 404);
        $canManage = Gate::allows('integrations.write');
        $accounts = DB::table('channel_accounts')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)->orderByDesc('updated_at')->get();
        $items = [
            ['telegram', 'Telegram', 'Переписка с клиентами в Telegram.', 'seller.channels.telegram'],
            ['widget', 'Виджет', 'Общение с клиентами на сайте.', 'seller.widget.index'],
            ['api', 'API и вебхуки', 'Ключи доступа и события для интеграций.', 'integrations.api'],
        ];
        $connections = [];
        foreach ($items as [$type, $title, $description, $route]) {
            $account = $accounts->firstWhere('type', $type);
            $restricted = ($type === 'api' && ! $canManage) || ($type === 'widget' && ! Gate::allows('seller.settings.write'));
            $connections[] = ['id' => $type, 'title' => $title, 'description' => $description,
                'status' => $account?->status ?? 'disconnected', 'connected_at' => $account?->connected_at,
                'settings_restricted' => $restricted, 'href' => ! $restricted && Route::has($route) ? route($route) : null];
        }
        $brand = Brand::query()->findOrFail($ctx->brandId);
        $notificationSettings = app(CompanyNotificationSettings::class)->forBrand($brand);
        $configured = $notificationSettings['telegram_configured'] || $notificationSettings['report_emails'] !== [];
        $connections[] = ['id' => 'notifications', 'title' => 'Уведомления компании', 'description' => 'Telegram и почта для сообщений команды.', 'status' => $configured ? 'active' : 'disconnected', 'connected_at' => null, 'settings_restricted' => ! $canManage, 'href' => $canManage ? route('integrations.notifications') : null];
        foreach (['WhatsApp', 'VK', 'amoCRM', 'Bitrix24'] as $title) {
            $connections[] = ['id' => strtolower($title), 'title' => $title, 'description' => 'Интеграция появится позже.', 'status' => 'soon', 'connected_at' => null, 'href' => null];
        }

        $priceMail = DB::table('seller_email_price_settings')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)->first();
        $sellerEnabled = app(SellerModuleAccess::class)->enabled($ctx->organizationId, $ctx->brandId);
        $canConfigurePriceMail = Gate::allows('seller.settings.write') && $sellerEnabled;
        $connections[] = ['id' => 'price_email', 'title' => 'Почта для прайса', 'description' => 'Отправка прайс-листа клиентам по почте.',
            'status' => ! $sellerEnabled ? 'disabled' : ($priceMail?->enabled ? 'configured' : 'disconnected'), 'connected_at' => null,
            'settings_restricted' => ! Gate::allows('seller.settings.write'), 'href' => $canConfigurePriceMail && Route::has('seller.channels.email.index') ? route('seller.channels.email.index') : null];

        $crmEvents = ['seller.contact.captured', 'seller.conversation.handed_off'];
        $crmWebhook = $sellerEnabled ? DB::table('webhooks')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)
            ->get(['events', 'created_at'])
            ->first(fn ($row): bool => array_intersect($crmEvents, json_decode($row->events, true, 512, JSON_THROW_ON_ERROR)) !== []) : null;
        $canConfigureCrm = $sellerEnabled && Gate::allows('seller.settings.read');
        $connections[] = ['id' => 'crm_webhook', 'title' => 'CRM-вебхук', 'description' => 'Передавайте события и контакты из EYON во внешнюю CRM.',
            'status' => ! $sellerEnabled ? 'disabled' : ($crmWebhook ? 'connected' : 'disconnected'), 'connected_at' => $crmWebhook->created_at ?? null,
            'settings_restricted' => ! Gate::allows('seller.settings.read'), 'href' => $canConfigureCrm && Route::has('seller.integrations.crm') ? route('seller.integrations.crm') : null];

        return Inertia::render('Shell/Integrations/Index', compact('connections'));
    }

    public function api(Request $request, WebhookService $webhooks, BrandReadApiAvailability $brandReadApi): Response
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);

        return Inertia::render('Shell/Integrations/Api', [
            'keys' => KeysController::listing($context),
            'webhooks' => $webhooks->forBrand($context),
            'webhookEvents' => WebhookService::eventOptions(),
            'permissions' => ['manage' => true],
            'apiBrandReadConfigured' => $brandReadApi->configured($request),
            'apiKeyRuntimeAvailable' => false,
            'secureSecretTransport' => SecretTransport::allows($request),
            'secretStorageReady' => SecretSessionStore::ready(),
            'webhookDeliveryAvailable' => WebhookDelivery::available(),
            'integration_api_key_available' => $this->keepSecretForPage($request, $context, 'integration_api_key'),
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
        $request->validate(['type' => ['required', Rule::in(['api_key'])]]);
        $key = 'integration_api_key';

        // The shared service locks session, user, organization, membership and brand in order.
        $value = $access->change($request, $context->brandId, function (Brand $brand, TenantContext $locked) use ($request, $key, $envelope): string {
            Gate::authorize('integrations.write');
            abort_unless($request->session()->get('brand_id') === $brand->id, 404);
            $stored = $request->session()->pull($key);
            $payload = $envelope->open($request, $locked, $key, $stored);
            abort_unless($payload !== null, 404);
            abort_unless($this->activeApiKey($locked, $payload['value']), 404);
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
        if ($payload === null || ! $this->activeApiKey($context, $payload['value'])
            || ! DB::table('integration_secret_receipts')->where('nonce_hash', $payload['nonce_hash'])
                ->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
            $request->session()->forget($key);

            return false;
        }

        return true;
    }

    private function activeApiKey(TenantContext $context, #[SensitiveParameter] string $value): bool
    {
        [$id, $token] = array_pad(explode('|', $value, 2), 2, null);
        if (! is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1
            || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) > 0)
            || ! is_string($token) || preg_match('/^[a-zA-Z0-9]{64}$/D', $token) !== 1) {
            return false;
        }

        return DB::table('personal_access_tokens')->where('id', $id)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->where('tokenable_type', Brand::class)->where('tokenable_id', $context->brandId)
            ->where('token', hash('sha256', $token))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    public function notifications(Request $request, CompanyNotificationSettings $settings): Response
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);
        $brand = Brand::query()->findOrFail($context->brandId);
        $session = $request->session()->get('company_telegram_code');
        $link = DB::table('company_telegram_links')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->first();
        $code = is_array($session) && ($session['brand'] ?? null) === $context->brandId && is_string($session['code'] ?? null) && $link && hash_equals($link->code_hash, hash('sha256', $session['code'])) ? $session['code'] : null;

        return Inertia::render('Shell/Integrations/Notifications', [
            ...$settings->forBrand($brand),
            'telegram_link' => ['code' => $code, 'pending' => (bool) ($code && $link?->pending_chat), 'bot' => $code ? config('features.E1-08.defaults.telegram_bot_username') : null],
            'company_deliveries' => DB::table('company_notification_deliveries')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['id', 'event_key', 'channel', 'status', 'created_at']),
            'external_delivery_enabled' => $this->externalDeliveryEnabled(),
            'permissions' => ['manage' => true],
        ]);
    }

    public function apiDocumentation(): Response
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);

        return Inertia::render('Shell/Integrations/ApiDocumentation');
    }

    public function saveNotifications(Request $request, BrandAccess $access, CompanyNotificationSettings $settings): RedirectResponse
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->brandId, 404);
        $data = $request->validate([
            'telegram_chat' => ['nullable', 'string', 'regex:/^-?[0-9]{1,20}$/'],
            'report_emails' => ['present', 'array', 'max:5'],
            'report_emails.*' => ['required', 'email', 'max:254', 'distinct:ignore_case'],
            'matrix' => ['sometimes', 'array:handoff,unknown_question,report_published,limit_80'],
            'matrix.*' => ['array:cabinet,email,telegram'],
            'matrix.*.*' => ['boolean'],
        ]);
        $access->change($request, $context->brandId, function (Brand $brand) use ($settings, $data): void {
            Gate::authorize('integrations.write');
            $settings->save($brand, $data['telegram_chat'] ?? null, $data['report_emails'], $data['matrix'] ?? null);
        });

        return redirect()->route('integrations.notifications')->with('success', $this->externalDeliveryEnabled() ? 'Настройки сохранены.' : 'Настройки сохранены. Внешняя доставка пока выключена.');
    }

    public function testNotifications(Request $request): RedirectResponse
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);
        $data = $request->validate(['channel' => ['required', Rule::in(['telegram', 'email'])]]);
        $settings = app(CompanyNotificationSettings::class)->forBrand(Brand::query()->findOrFail($context->brandId));
        $available = $data['channel'] === 'telegram' ? $settings['telegram_configured'] : count($settings['report_emails']) > 0;
        abort_unless($available, 422, 'Сначала настройте выбранный канал.');

        return redirect()->route('integrations.notifications')->with('success', 'Проверка пройдена в fake режиме. Сообщения не отправлялись.');
    }
}
