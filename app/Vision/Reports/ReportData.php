<?php

namespace App\Vision\Reports;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;

final class ReportData
{
    public function siteFromProfile(?object $measurement): ?string
    {
        $profile = $measurement && is_string($measurement->profile_snapshot) ? json_decode($measurement->profile_snapshot, true) : null;

        return is_array($profile) && is_string($measurement->profile_checksum) && hash_equals($measurement->profile_checksum, CanonicalJson::hash($profile)) && is_string($profile['site'] ?? null) ? $profile['site'] : null;
    }

    public function read(TenantContext $context, ?string $reportId): array
    {
        $confirmed = DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->orderByDesc('created_at')
            ->get(['measurement_id', 'created_at']);
        $reports = DB::table('vision_reports')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->whereIn('measurement_id', $confirmed->pluck('measurement_id'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'measurement_id', 'revision', 'created_at']);
        $selected = $reportId ?? $reports->first()?->id;
        $report = null;
        if ($selected !== null) {
            abort_unless($reports->contains('id', $selected), 404);
            $payload = app(VisionReportReader::class)->read($selected);
            $changes = null;
            $seen = false;
            foreach ($reports as $candidate) {
                if ($candidate->id === $selected) {
                    $seen = true;

                    continue;
                }
                if (! $seen || $candidate->measurement_id === $payload['measurement_id']) {
                    continue;
                }
                try {
                    $previous = app(VisionReportReader::class)->read($candidate->id);
                    $changes = app(ReportSections::class)->compare($previous, $payload);
                } catch (\LogicException) {
                    // An inaccessible previous snapshot must not hide the selected report.
                }
                break;
            }
            $measurement = DB::table('measurements')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $payload['measurement_id'])->first(['profile_snapshot', 'profile_checksum']);
            $site = $this->siteFromProfile($measurement);
            $siteAudit = null;
            if ($site) {
                $auditRows = DB::table('audit_runs')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('created_at', '<=', $reports->firstWhere('id', $selected)->created_at)->orderByDesc('created_at')->orderByDesc('id')->get(['result', 'created_at']);
                foreach ($auditRows as $auditRow) {
                    $audit = json_decode($auditRow->result, true);
                    if (is_array($audit) && is_string($audit['url'] ?? null) && rtrim($audit['url'], '/') === rtrim($site, '/') && is_array($audit['readiness'] ?? null) && is_numeric($audit['readiness']['lower'] ?? null) && is_numeric($audit['readiness']['upper'] ?? null)) {
                        $siteAudit = ['readiness' => $audit['readiness'], 'date' => $auditRow->created_at, 'criteria' => SiteCriteria::project($audit['criteria'] ?? [])];
                        break;
                    }
                }
            }
            $metrics = $payload['metrics'] ?? [];
            $panel = $metrics['panel'] ?? [];
            $visibility = $metrics['visibility'] ?? [];
            $report = [
                'id' => $selected, 'measurement_id' => $payload['measurement_id'],
                'sections' => [...app(ReportSections::class)->make($payload), 'changes' => $changes, 'site' => $site, 'site_audit' => $siteAudit],
                'visibility' => $visibility['macro_percent'] ?? null,
                'visibility_kind' => $visibility['macro_kind'] ?? 'available',
                'coverage' => $visibility['coverage'] ?? null,
                'share_of_voice' => $panel['share_of_voice'] ?? null,
                'position' => $panel['position_average'] ?? null,
                'verified_errors' => is_array($payload['reviewed_facts'] ?? null)
                    ? count(array_filter($payload['reviewed_facts'], fn ($item) => ($item['confirmed'] ?? false) === true)) : null,
                'providers' => collect($metrics['providers'] ?? [])->map(fn ($row, $name) => [
                    'name' => $name, 'mention_rate' => $row['mention_rate'] ?? null,
                    'coverage' => $row['coverage'] ?? null,
                ])->values()->all(),
                'questions' => count($payload['questions'] ?? []),
                'evidence_status' => $payload['evidence_status'] ?? 'not_available',
            ];
        }

        return [
            'confirmed' => $confirmed->map(fn ($row) => ['measurement_id' => $row->measurement_id, 'confirmed_at' => $row->created_at])->all(),
            'reports' => $reports->map(fn ($row) => ['id' => $row->id, 'measurement_id' => $row->measurement_id,
                'revision' => $row->revision, 'created_at' => $row->created_at])->all(),
            'report' => $report,
        ];
    }
}
