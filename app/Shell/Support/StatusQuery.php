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
            $components[] = ['id' => $id, 'name' => __('Shell/Support/messages.components.'.$id), 'status' => 'unknown',
                'message' => __('Shell/Support/messages.monitoring_unavailable'), 'source' => 'unavailable', 'checked_at' => null];
        }
        try {
            $models = $this->catalog->models();
            $snapshot = $this->cooldowns->snapshot(app(TenantContext::class));
        } catch (Throwable) {
            $models = [];
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
            $providers[$id] = ['id' => $id, 'name' => __('Shell/Support/messages.providers.'.$id),
                'status' => $planned ? 'planned' : ($until !== null ? 'cooldown' : 'unknown'), 'cooldown_until' => $until,
                'checked_at' => $snapshot['available'] ? $snapshot['checked_at'] : null, 'source' => $snapshot['available'] ? 'cooldown_projection' : 'configuration'];
        }

        return ['checked_at' => $checked, 'health_url' => '/up', 'components' => $components,
            'providers' => ['available' => $snapshot['available'], 'items' => array_values($providers),
                'message' => __($snapshot['available'] ? 'Shell/Support/messages.provider_status_available' : 'Shell/Support/messages.provider_status_unavailable')],
            'incidents' => ['available' => false, 'data' => []]];
    }
}
