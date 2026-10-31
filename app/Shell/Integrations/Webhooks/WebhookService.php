<?php

namespace App\Shell\Integrations\Webhooks;

use App\Models\Brand;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Brands\BrandAccess;
use App\Shell\Brands\Network\PublicHost;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class WebhookService
{
    public const EVENTS = [
        'seller.lead.created' => 'Новый лид',
        'seller.conversation.handed_off' => 'Передача менеджеру',
        'seller.contact.captured' => 'Контакт получен',
        'vision.measurement.completed' => 'Замер готов',
        'vision.report.published' => 'Отчёт опубликован',
    ];

    public static function eventOptions(): array
    {
        $options = [];
        foreach (self::EVENTS as $value => $label) {
            $options[] = compact('value', 'label');
        }

        return $options;
    }

    public function forBrand(TenantContext $ctx): array
    {
        if (! $ctx->organizationId || ! $ctx->brandId) {
            return [];
        }

        return DB::table('webhooks')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)
            ->orderBy('created_at')->get()->map(function ($row) use ($ctx): array {
                $deliveries = DB::table('webhook_deliveries')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)
                    ->where('webhook_id', $row->id)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['id', 'event', 'status', 'status_code', 'created_at']);

                return ['id' => $row->id, 'url' => $row->url, 'events' => json_decode($row->events, true, 512, JSON_THROW_ON_ERROR),
                    'secret' => '••••••••', 'deliveries' => $deliveries->toArray()];
            })->all();
    }

    public function store(Request $request): string
    {
        $ctx = app(TenantContext::class);
        abort_unless($ctx->brandId, 404);
        app(BrandAccess::class)->read($request, $ctx->brandId, 'integrations.write');
        $data = $request->validate([
            'url' => ['required', 'string', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1', 'max:5'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::EVENTS))],
        ]);
        $parts = parse_url($data['url']);
        try {
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new InvalidArgumentException;
            }
            PublicHost::normalize($parts['host'] ?? '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['url' => 'Укажите публичный HTTPS-адрес без логина, пароля и нестандартного порта.']);
        }

        return app(BrandAccess::class)->change($request, $ctx->brandId, function (Brand $brand, TenantContext $tenant) use ($data): string {
            $secret = bin2hex(random_bytes(32));
            $id = (string) Str::ulid();
            DB::table('webhooks')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $brand->id,
                'url' => $data['url'], 'events' => json_encode(array_values($data['events']), JSON_THROW_ON_ERROR),
                'secret' => Crypt::encryptString($secret), 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('integrations.webhook.created', 'webhook', $id, after: ['events' => $data['events']]));

            return $secret;
        });
    }

    public function test(Request $request, string $id): void
    {
        $ctx = app(TenantContext::class);
        abort_unless($ctx->brandId, 404);
        app(BrandAccess::class)->read($request, $ctx->brandId, 'integrations.write');
        app(BrandAccess::class)->change($request, $ctx->brandId, function (Brand $brand, TenantContext $tenant) use ($id): void {
            $hook = DB::table('webhooks')->where('organization_id', $tenant->organizationId)->where('brand_id', $brand->id)->where('id', $id)->first();
            abort_unless($hook, 404);
            abort_unless(config('features.E1-10.defaults.webhook_delivery', false), 503, 'Отправка вебхуков отключена.');
            $deliveryId = (string) Str::ulid();
            $payload = json_encode(['id' => $deliveryId, 'type' => 'webhook.test', 'occurred_at' => now()->toIso8601String(), 'data' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('webhook_deliveries')->insert(['id' => $deliveryId, 'organization_id' => $tenant->organizationId, 'brand_id' => $brand->id,
                'webhook_id' => $id, 'event_id' => null, 'event' => 'webhook.test', 'payload' => $payload, 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('integrations.webhook.test_requested', 'webhook', $id));
            DeliverWebhook::dispatch($deliveryId)->afterCommit();
        });
    }
}
