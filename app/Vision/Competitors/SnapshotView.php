<?php

namespace App\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Core\VisibilityMetrics;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;

class SnapshotView
{
    public function current(TenantContext $tenant): ?array
    {
        $confirmed = DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->pluck('measurement_id');
        $id = DB::table('vision_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->whereIn('measurement_id', $confirmed)->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return $id ? app(VisionReportReader::class)->read($id) : null;
    }

    public function metrics(array $snapshot, array $aliases): array
    {
        $original = $snapshot['metrics'] ?? [];
        if (! $aliases) {
            return $original;
        }
        $slots = $original['slots'] ?? [];
        $providers = array_keys($snapshot['providers'] ?? []);
        if (! $slots || ! $providers || empty($snapshot['questions']) || empty($snapshot['runs'])) {
            return $original;
        }
        $groups = array_values(array_unique(array_values($aliases)));
        foreach ($slots as &$slot) {
            foreach ($slot['mentions'] ?? [] as $index => $mention) {
                $name = $mention['name'] ?? null;
                if (! is_string($name)) {
                    continue;
                }
                $canonical = $aliases[$name] ?? $name;
                if ($canonical !== $name || in_array($canonical, $groups, true)) {
                    $slot['mentions'][$index]['name'] = $canonical;
                    $slot['mentions'][$index]['entity_id'] = 'merged:'.hash('sha256', $canonical);
                }
            }
        }
        unset($slot);

        return app(VisibilityMetrics::class)->calculate($slots, $providers, count($snapshot['questions']), (int) $snapshot['runs']);
    }
}
