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

    /** Recompute missing position aggregates in memory; never mutate the stored report. */
    private function withHistoricalPositions(array $snapshot, array $original): array
    {
        $entities = $original['entities'] ?? null;
        $slots = $original['slots'] ?? null;
        $providers = $snapshot['providers'] ?? null;
        $questions = $snapshot['questions'] ?? null;
        $runs = $snapshot['runs'] ?? null;
        if (! is_array($entities) || ! is_array($slots) || ! $slots || ! is_array($providers) || ! $providers
            || ! is_array($questions) || ! $questions || ! is_int($runs) || $runs < 1) {
            return $original;
        }
        try {
            $calculated = app(VisibilityMetrics::class)->calculate($slots, array_keys($providers), count($questions), $runs);
        } catch (\Throwable) {
            return $original;
        }
        foreach ($entities as &$entity) {
            if (! is_array($entity) || (isset($entity['position']) && isset($entity['positions_by_provider'])) || ! is_string($entity['name'] ?? null)) {
                continue;
            }
            $matches = array_values(array_filter($calculated['entities'] ?? [], fn ($candidate) => is_array($candidate)
                && ($candidate['name'] ?? null) === $entity['name'] && ($candidate['is_brand'] ?? false) === ($entity['is_brand'] ?? false)));
            if (count($matches) === 1 && isset($matches[0]['position'])) {
                $entity['position'] ??= $matches[0]['position'];
                $entity['positions_by_provider'] ??= $matches[0]['positions_by_provider'] ?? [];
                $entity['positions_by_intent'] ??= $matches[0]['positions_by_intent'] ?? [];
            }
        }
        unset($entity);
        $original['entities'] = $entities;

        return $original;
    }

    public function metrics(array $snapshot, array $aliases): array
    {
        $original = $snapshot['metrics'] ?? [];
        if (! $aliases) {
            return $this->withHistoricalPositions($snapshot, $original);
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
