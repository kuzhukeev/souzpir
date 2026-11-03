<?php

namespace App\Shell\Support;

use App\Ai\ProviderCatalog;
use App\Shell\Support\Contracts\ProviderCooldowns;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StatusQuery
{
    public function __construct(private ProviderCatalog $catalog, private ProviderCooldowns $cooldowns) {}

    public function read(): array
    {
        $checked = now()->toIso8601String();
        try {
            $observed = $this->observed(app(TenantContext::class));
        } catch (Throwable) {
            $observed = ['components' => [], 'providers' => [], 'incidents' => []];
        }
        $database = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = false;
        }
        $components = [
            ['id' => 'cabinet', 'name' => __('Shell/Support/messages.components.cabinet'), 'status' => 'operational', 'message' => __('Shell/Support/messages.app_checked'), 'source' => 'app_response', 'checked_at' => $checked],
            ['id' => 'database', 'name' => __('Shell/Support/messages.components.database'), 'status' => $database ? 'operational' : 'degraded', 'message' => __($database ? 'Shell/Support/messages.db_checked' : 'Shell/Support/messages.db_unavailable'), 'source' => 'database_probe', 'checked_at' => $checked],
        ];
        foreach (['widget', 'telegram', 'vision'] as $id) {
            $signal = $observed['components'][$id] ?? null;
            $components[] = ['id' => $id, 'name' => __('Shell/Support/messages.components.'.$id), 'status' => $signal['status'] ?? 'unknown',
                'message' => $signal['message'] ?? __('Shell/Support/messages.monitoring_unavailable'), 'source' => $signal['source'] ?? 'unavailable', 'checked_at' => $signal['checked_at'] ?? null];
        }
        try {
            $models = $this->catalog->models();
        } catch (Throwable) {
            $models = [];
        }
        try {
            $snapshot = $this->cooldowns->snapshot(app(TenantContext::class));
        } catch (Throwable) {
            $snapshot = ['available' => false, 'checked_at' => null, 'cooldowns' => []];
        }
        $providers = [];
        foreach ($models as $model) {
            $id = $model['provider'];
            $planned = ($model['status'] ?? null) === 'planned';
            if (isset($providers[$id]) && $planned) {
                continue;
            }
            $until = null;
            if ($snapshot['available'] && isset($snapshot['cooldowns'][$id])) {
                $candidate = CarbonImmutable::parse($snapshot['cooldowns'][$id]);
                $until = $candidate->isFuture() ? $candidate->toIso8601String() : null;
            }
            $signal = $observed['providers'][$id] ?? null;
            $providers[$id] = ['id' => $id, 'name' => __('Shell/Support/messages.providers.'.$id),
                'status' => $planned ? 'planned' : ($until !== null ? 'cooldown' : ($signal['status'] ?? 'unknown')), 'cooldown_until' => $until,
                'checked_at' => $until !== null ? $snapshot['checked_at'] : ($signal['checked_at'] ?? ($snapshot['available'] ? $snapshot['checked_at'] : null)),
                'source' => $until !== null ? 'cooldown_projection' : ($signal['source'] ?? ($snapshot['available'] ? 'cooldown_projection' : 'configuration'))];
        }

        return ['checked_at' => $checked, 'health_url' => '/up', 'components' => $components,
            'providers' => ['available' => $snapshot['available'] || count($observed['providers']) > 0, 'items' => array_values($providers),
                'message' => __($snapshot['available'] || count($observed['providers']) > 0 ? 'Shell/Support/messages.provider_status_available' : 'Shell/Support/messages.provider_status_unavailable')],
            'incidents' => ['available' => count($observed['incidents']) > 0, 'data' => $observed['incidents']]];
    }

    private function observed(TenantContext $tenant): array
    {
        $where = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $since = now()->subDays(30);
        $components = [];
        $incidents = [];
        foreach (['widget', 'telegram'] as $type) {
            $accounts = DB::table('channel_accounts')->where($where)->where('type', $type)->where('status', 'active')->pluck('id');
            if ($accounts->isEmpty()) {
                continue;
            }
            $event = DB::table('channel_inbound_events')->where($where)->whereIn('channel_account_id', $accounts)
                ->where('created_at', '>=', $since)->orderByDesc('created_at')->first(['id', 'created_at', 'processed_at']);
            if ($event) {
                $stalled = $event->processed_at === null && CarbonImmutable::parse($event->created_at)->lt(now()->subMinutes(5));
                $components[$type] = ['status' => $stalled ? 'degraded' : ($event->processed_at ? 'operational' : 'unknown'),
                    'message' => $stalled ? 'Последнее входящее событие не обработано.' : 'Последнее входящее событие обработано.',
                    'source' => 'channel_event', 'checked_at' => $event->processed_at ?? $event->created_at];
                if ($stalled) {
                    $incidents[] = ['id' => $event->id, 'title' => 'Не обработано событие канала '.($type === 'widget' ? 'Виджет' : 'Telegram'), 'occurred_at' => $event->created_at];
                }
            }
        }
        $measurement = DB::table('measurements')->where($where)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->first(['id', 'status', 'updated_at']);
        if ($measurement && in_array($measurement->status, ['completed', 'failed'], true)) {
            $failed = $measurement->status === 'failed';
            $components['vision'] = ['status' => $failed ? 'degraded' : 'operational',
                'message' => $failed ? 'Последний замер завершился ошибкой.' : 'Последний замер завершён.',
                'source' => 'measurement', 'checked_at' => $measurement->updated_at];
        }
        $failedMeasurements = DB::table('measurements')->where($where)->where('updated_at', '>=', $since)
            ->where('status', 'failed')->orderByDesc('updated_at')->limit(20)->get(['id', 'updated_at']);
        foreach ($failedMeasurements as $failedMeasurement) {
            $incidents[] = ['id' => $failedMeasurement->id, 'title' => 'Ошибка замера Видения', 'occurred_at' => $failedMeasurement->updated_at];
        }
        $providers = [];
        $calls = DB::table('ai_calls')->where($where)->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')->limit(200)->get(['id', 'provider', 'status', 'updated_at']);
        foreach ($calls as $call) {
            if (! isset($providers[$call->provider]) && in_array($call->status, ['completed', 'failed', 'uncertain'], true)) {
                $failed = $call->status !== 'completed';
                $providers[$call->provider] = ['status' => $failed ? 'degraded' : 'operational', 'source' => 'ai_call', 'checked_at' => $call->updated_at];
            }
            if (in_array($call->status, ['failed', 'uncertain'], true) && count($incidents) < 20) {
                $incidents[] = ['id' => $call->id, 'title' => 'Ошибка вызова ИИ: '.$call->provider, 'occurred_at' => $call->updated_at];
            }
        }
        usort($incidents, fn (array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));

        return ['components' => $components, 'providers' => $providers, 'incidents' => array_slice($incidents, 0, 20)];
    }
}
