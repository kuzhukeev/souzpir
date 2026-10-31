<?php

namespace App\Shell\Integrations\Webhooks;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                            'status' => in_array($delivery->status, ['pending', 'sending', 'sent', 'failed'], true) ? $delivery->status : 'unknown',
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
}
