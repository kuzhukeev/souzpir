<?php

namespace App\Vision\Settings;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Carbon;
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
        $tracked = DB::table('vision_competitor_tracking')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->orderBy('created_at')->orderBy('id')->limit(5)->pluck('name')->all();
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->measurement_id])) {
                continue;
            }
            $seen[$row->measurement_id] = true;
            $result[] = $this->project(app(VisionReportReader::class)->read($row->id), $row->id, (string) $row->created_at, $tracked);
            if (count($result) === 20) {
                break;
            }
        }

        return $this->withActions($tenant, array_reverse($result));
    }

    public function withActions(TenantContext $tenant, array $result): array
    {
        if (count($result) < 2) {
            return $result;
        }
        $actions = DB::table('actions')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->whereNotNull('completed_at')
            ->where('updated_at', '>', $result[0]['date'])
            ->where('updated_at', '<=', $result[array_key_last($result)]['date'])
            ->orderBy('updated_at')
            ->get(['title', 'updated_at']);
        foreach ($result as $index => &$row) {
            $row['share_change_pp'] = $index > 0 && $row['share'] !== null && $result[$index - 1]['share'] !== null
                ? round($row['share'] - $result[$index - 1]['share'], 2) : null;
            $row['actions'] = $index === 0 ? [] : $actions->filter(fn ($action) => Carbon::parse($action->updated_at)->greaterThan(Carbon::parse($result[$index - 1]['date']))
                && Carbon::parse($action->updated_at)->lessThanOrEqualTo(Carbon::parse($row['date'])))->pluck('title')->values()->all();
        }
        unset($row);

        return $result;
    }

    public function project(array $snapshot, string $id, string $date, array $tracked = []): array
    {
        $metrics = $snapshot['metrics'] ?? [];

        return [
            'id' => $id, 'date' => $date, 'signature' => isset($snapshot['question_set_checksum'], $snapshot['providers'], $snapshot['runs']) ? CanonicalJson::hash(['questions' => $snapshot['question_set_checksum'], 'providers' => $snapshot['providers'], 'runs' => $snapshot['runs']]) : null,
            'visibility' => data_get($metrics, 'visibility.macro_percent'),
            'share' => data_get($metrics, 'panel.share_of_voice'),
            'providers' => collect($metrics['providers'] ?? [])->map(fn ($row) => [
                'visibility' => $row['mention_rate'] ?? null, 'position' => $row['position_average'] ?? null,
            ])->all(),
            'intents' => collect($metrics['intents'] ?? [])->map(fn ($row) => $row['mention_rate'] ?? null)->all(),
            'entities' => collect($metrics['entities'] ?? [])->filter(fn ($row) => ! ($row['is_brand'] ?? false) && in_array($row['name'] ?? null, array_slice($tracked, 0, 5), true))->mapWithKeys(fn ($row) => [($row['name'] ?? '') => $row['share_of_voice'] ?? null])->all(),
        ];
    }
}
