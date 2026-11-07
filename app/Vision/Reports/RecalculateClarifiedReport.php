<?php

namespace App\Vision\Reports;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\ReportSeal;
use App\Vision\Core\VisionReportReader;
use App\Vision\Core\WorkloadLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecalculateClarifiedReport
{
    /** @return array{before: float, after: float}|null */
    public function create(TenantContext $tenant, string $measurement): ?array
    {
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $source = DB::table('vision_reports')->where($scope)->where('measurement_id', $measurement)
            ->orderByDesc('revision')->orderByDesc('id')->first();
        if (! $source) {
            return null;
        }
        try {
            $payload = app(VisionReportReader::class)->read($source->id);
        } catch (\Throwable) {
            return null;
        }
        $adjusted = app(ClarificationVisibility::class)->apply($tenant, $payload);
        if ($adjusted['applied'] === 0) {
            return null;
        }
        $before = $payload['metrics']['visibility']['macro_percent'] ?? null;
        $after = $adjusted['metrics']['visibility']['macro_percent'] ?? null;
        if (! is_numeric($before) || ! is_numeric($after)) {
            return null;
        }
        $result = ['before' => (float) $before, 'after' => (float) $after];
        $payload['metrics'] = $adjusted['metrics'];
        $payload['clarified_from'] = $source->id;
        $checksum = CanonicalJson::hash($payload);
        if (DB::table('vision_reports')->where($scope)->where('measurement_id', $measurement)->where('checksum', $checksum)->exists()) {
            return $result;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $size = DB::selectOne('select octet_length(cast(cast(? as jsonb) as text)) as bytes', [$encoded]);
        if (! $size || (int) $size->bytes > WorkloadLimits::dataBytes()) {
            return $result;
        }
        $revision = 1 + (int) DB::table('vision_reports')->where($scope)->where('measurement_id', $measurement)->max('revision');
        $id = (string) Str::ulid();
        DB::table('vision_reports')->insert(['id' => $id, ...$scope, 'measurement_id' => $measurement, 'revision' => $revision,
            'payload' => $encoded, 'checksum' => $checksum,
            'snapshot_mac' => ReportSeal::mac($scope['organization_id'], $scope['brand_id'], $id, $measurement, $revision, $checksum),
            'created_at' => now(), 'updated_at' => now()]);

        return $result;
    }
}
