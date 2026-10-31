<?php

namespace App\Vision\Site;

use App\Tenancy\TenantContext;
use App\Vision\Core\MeasurementPlan;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only projection. Every displayed fact is revalidated through the sealed report reader. */
final class History
{
    public function __construct(private VisionReportReader $reader, private SiteAccess $access) {}

    public function read(TenantContext $tenant): array
    {
        $empty = ['status' => 'too_early', 'before' => null, 'after' => null, 'delta' => null, 'coverage' => null, 'paired_slots' => 0];
        $rows = DB::table('vision_reports as r')->join('measurements as m', function ($join): void {
            $join->on('m.id', '=', 'r.measurement_id')->on('m.organization_id', '=', 'r.organization_id')->on('m.brand_id', '=', 'r.brand_id');
        })->where('r.organization_id', $tenant->organizationId)->where('r.brand_id', $tenant->brandId)
            ->whereRaw('r.revision = (select max(r2.revision) from vision_reports r2 where r2.measurement_id = r.measurement_id and r2.organization_id = r.organization_id and r2.brand_id = r.brand_id)')
            ->orderByRaw('m.finished_at DESC NULLS FIRST')->orderByDesc('m.id')->orderByDesc('r.revision')->limit(20)
            ->get(['r.id', 'r.measurement_id', 'm.finished_at']);
        $reports = [];
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->measurement_id])) {
                continue;
            }
            $seen[$row->measurement_id] = true;
            try {
                $payload = $this->reader->read($row->id);
                $plan = MeasurementPlan::fetch(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId], $row->measurement_id);
                $configuration = json_decode($plan->configuration, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($configuration) || ! is_string($plan->profile_checksum) || $plan->profile_checksum === '' || $row->finished_at === null) {
                    throw new \LogicException;
                }
                $reports[] = ['id' => $row->id, 'payload' => $payload];
            } catch (Throwable) {
                // Never substitute an older valid snapshot for an unavailable latest report.
                return ['achievements' => [], 'comparison' => $empty];
            }
            $this->access->lock($tenant, false, 'vision.report.read');
            break;
        }
        $achievements = [];
        $latest = $reports[0] ?? null;
        if ($latest && ($latest['payload']['evidence_status'] ?? null) === 'available'
            && (int) data_get($latest['payload'], 'metrics.panel.mentioned', 0) > 0
            && (int) data_get($latest['payload'], 'metrics.panel.mention_denominator', 0) > 0) {
            $achievements[] = ['code' => 'confirmed_mention', 'title' => 'Подтверждённое упоминание',
                'earned_at' => null, 'report_id' => $latest['id'],
                'note' => 'По проверенному отчёту; дата и причина изменения не подтверждены подписью.'];
        }

        // Measurement timestamps are not covered by the report seal. Until their ordering
        // has an immutable contract, even signed snapshots cannot prove before/after.
        return ['achievements' => $achievements, 'comparison' => $empty];
    }
}
