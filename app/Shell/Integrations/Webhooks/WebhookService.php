<?php

namespace App\Shell\Integrations\Webhooks;

use App\Models\Brand;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Brands\BrandAccess;
use App\Shell\Brands\Network\PublicHost;
use App\Tenancy\TenantContext;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            ->orderBy('created_at')->get(['id', 'events'])->map(function ($row) use ($ctx): array {
                $deliveries = DB::table('webhook_deliveries')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)
                    ->where('webhook_id', $row->id)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['id', 'event', 'status', 'status_code', 'created_at'])
                    ->map(static function ($delivery): array {
                        return [
                            'id' => $delivery->id,
                            'event' => isset(self::EVENTS[$delivery->event]) || $delivery->event === 'webhook.test' ? $delivery->event : 'unknown',
                            'status' => in_array($delivery->status, ['pending', 'sending', 'sent', 'failed', 'simulated'], true) ? $delivery->status : 'unknown',
                            'status_code' => $delivery->status_code,
                            'created_at' => $delivery->created_at,
                        ];
                    })->all();

                $events = json_decode($row->events, true, 512, JSON_THROW_ON_ERROR);
                $events = is_array($events) ? array_values(array_filter($events, static fn ($event): bool => is_string($event) && isset(self::EVENTS[$event]))) : [];

                return ['id' => $row->id, 'events' => $events, 'secret' => '••••••••', 'deliveries' => $deliveries];
            })->all();
    }

    public function store(#[\SensitiveParameter] Request $request): string
    {
        abort(503, 'Настройка вебхуков временно недоступна.');
    }

    public function test(#[\SensitiveParameter] Request $request, string $id): void
    {
        abort(503, 'Отправка вебхуков пока недоступна.');
    }

    public function configure(#[\SensitiveParameter] Request $request): void
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);
        $input = $request->attributes->get('webhook_configuration');
        abort_unless(is_array($input), 400);
        $data = Validator::make($input, [
            'url' => ['required', 'string', 'url', 'max:1000'],
            'events' => ['required', 'array', 'min:1', 'max:5'],
            'events.*' => ['required', 'distinct', Rule::in(array_keys(self::EVENTS))],
            'secret' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        $parts = parse_url($data['url']);
        try {
            $host = is_array($parts) ? PublicHost::normalize($parts['host'] ?? '') : '';
        } catch (Throwable) {
            $host = '';
        }
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw ValidationException::withMessages(['url' => 'Укажите HTTPS адрес публичного домена без параметров и учётных данных.']);
        }
        $url = 'https://'.$host.($parts['path'] ?? '/');
        app(BrandAccess::class)->change($request, $context->brandId, function (Brand $brand, TenantContext $locked) use ($data, $url): void {
            Gate::authorize('integrations.write');
            $id = (string) Str::ulid();
            DB::table('webhooks')->insert([
                'id' => $id, 'organization_id' => $locked->organizationId, 'brand_id' => $brand->id,
                'url' => Crypt::encryptString($url), 'events' => json_encode(array_values($data['events']), JSON_THROW_ON_ERROR),
                'secret' => Crypt::encryptString($data['secret']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditWriter::class)->record($locked, new AuditEntry('integration.webhook.created', 'webhook', $id, after: ['events' => array_values($data['events'])]));
        });
    }

    public function fakeTest(Request $request, string $id): void
    {
        Gate::authorize('integrations.write');
        $context = app(TenantContext::class);
        abort_unless($context->organizationId && $context->brandId, 404);
        app(BrandAccess::class)->change($request, $context->brandId, function (Brand $brand, TenantContext $locked) use ($id): void {
            Gate::authorize('integrations.write');
            $hook = DB::table('webhooks')->where('id', $id)->where('organization_id', $locked->organizationId)
                ->where('brand_id', $brand->id)->first();
            abort_unless($hook, 404);
            // Guzzle's in-memory handler exercises the HTTP request shape without a network socket.
            $fake = new Client(['handler' => new MockHandler([new Response(200)])]);
            $url = str_starts_with($hook->url, 'https://') ? $hook->url : Crypt::decryptString($hook->url);
            $statusCode = $fake->post($url, ['json' => ['event' => 'webhook.test']])->getStatusCode();
            DB::table('webhook_deliveries')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $locked->organizationId, 'brand_id' => $brand->id,
                'webhook_id' => $id, 'event' => 'webhook.test', 'payload' => '{}', 'status' => 'simulated',
                'attempts' => 0, 'status_code' => $statusCode, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
