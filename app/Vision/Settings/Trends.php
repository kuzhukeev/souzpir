<?php

namespace App\Vision\Settings;

use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;

final class Trends
{
    public function read(TenantContext $tenant): array
    {
        $confirmed = DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->pluck('measurement_id');
        $rows = DB::table('vision_reports')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->whereIn('measurement_id', $confirmed)
            ->orderByDesc('created_at')->orderByDesc('revision')->get(['id', 'measurement_id', 'created_at']);
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->measurement_id])) {
                continue;
            }
            $seen[$row->measurement_id] = true;
            $result[] = $this->project(app(VisionReportReader::class)->read($row->id), $row->id, (string) $row->created_at);
            if (count($result) === 20) {
                break;
            }
        }

        return array_reverse($result);
    }

    public function project(array $snapshot, string $id, string $date): array
    {
        $metrics = $snapshot['metrics'] ?? [];

        return [
            'id' => $id, 'date' => $date,
            'visibility' => data_get($metrics, 'visibility.macro_percent'),
            'share' => data_get($metrics, 'panel.share_of_voice'),
            'providers' => collect($metrics['providers'] ?? [])->map(fn ($row) => [
                'visibility' => $row['mention_rate'] ?? null, 'position' => $row['position_average'] ?? null,
            ])->all(),
            'intents' => collect($metrics['intents'] ?? [])->map(fn ($row) => $row['mention_rate'] ?? null)->all(),
            'entities' => collect($metrics['entities'] ?? [])->mapWithKeys(fn ($row) => [($row['name'] ?? '') => $row['share_of_voice'] ?? null])->all(),
        ];
    }
}
