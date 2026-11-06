<?php

namespace App\Vision\Reports;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\ConfirmedClarifications;
use App\Vision\Core\VisionReportReader;
use App\Vision\Publications\PublicationData;
use Illuminate\Support\Facades\DB;

final class ReportData
{
    public function siteFromProfile(?object $measurement): ?string
    {
        $profile = $measurement && is_string($measurement->profile_snapshot) ? json_decode($measurement->profile_snapshot, true) : null;

        return is_array($profile) && is_string($measurement->profile_checksum) && hash_equals($measurement->profile_checksum, CanonicalJson::hash($profile)) && is_string($profile['site'] ?? null) ? $profile['site'] : null;
    }

    public function siteAudit(TenantContext $context, ?string $site, string $reportDate): ?array
    {
        if (! $site) {
            return null;
        }
        $rows = DB::table('audit_runs')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->where('created_at', '<=', $reportDate)->orderByDesc('created_at')->orderByDesc('id')->get(['result', 'created_at']);
        foreach ($rows as $row) {
            $audit = is_string($row->result) ? json_decode($row->result, true) : null;
            if (is_array($audit) && is_string($audit['url'] ?? null) && rtrim($audit['url'], '/') === rtrim($site, '/')
                && is_array($audit['readiness'] ?? null) && is_numeric($audit['readiness']['lower'] ?? null) && is_numeric($audit['readiness']['upper'] ?? null)) {
                return ['readiness' => $audit['readiness'], 'date' => $row->created_at, 'criteria' => $audit['criteria'] ?? []];
            }
        }

        return null;
    }

    public function read(TenantContext $context, ?string $reportId): array
    {
        $confirmed = DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->orderByDesc('created_at')
            ->get(['measurement_id', 'created_at']);
        $reports = DB::table('vision_reports as r')
            ->join('measurements as m', function ($join): void {
                $join->on('m.id', '=', 'r.measurement_id')->on('m.organization_id', '=', 'r.organization_id')->on('m.brand_id', '=', 'r.brand_id');
            })
            ->where('r.organization_id', $context->organizationId)->where('r.brand_id', $context->brandId)
            ->whereIn('r.measurement_id', $confirmed->pluck('measurement_id'))
            ->orderByDesc('m.created_at')->orderByDesc('m.id')->orderByDesc('r.revision')->orderByDesc('r.id')
            ->get(['r.id', 'r.measurement_id', 'r.revision', 'r.created_at', 'm.created_at as measurement_created_at', 'm.finished_at as measurement_finished_at']);
        $selected = $reportId ?? $reports->first()?->id;
        $report = null;
        if ($selected !== null) {
            abort_unless($reports->contains('id', $selected), 404);
            $payload = app(VisionReportReader::class)->read($selected);
            $adjusted = app(ClarificationVisibility::class)->apply($context, $payload);
            $reviewed = app(ConfirmedClarifications::class)->read(['organization_id' => $context->organizationId, 'brand_id' => $context->brandId],
                $payload['measurement_id'], $payload['metrics']['slots'] ?? []);
            $changes = null;
            $history = [];
            $previousIntents = null;
            $selectedRow = $reports->firstWhere('id', $selected);
            $signature = app(ReportHistory::class)->signature($payload);
            $currentPoint = app(ReportHistory::class)->point($selectedRow, $payload);
            $seenMeasurements = [$payload['measurement_id'] => true];
            $pastSelected = false;
            foreach ($reports as $candidate) {
                if ($candidate->measurement_id === $payload['measurement_id']) {
                    $pastSelected = true;

                    continue;
                }
                if (! $pastSelected || isset($seenMeasurements[$candidate->measurement_id])) {
                    continue;
                }
                $seenMeasurements[$candidate->measurement_id] = true;
                try {
                    $previous = app(VisionReportReader::class)->read($candidate->id);
                    if ($signature === null || app(ReportHistory::class)->signature($previous) !== $signature) {
                        continue;
                    }
                    $point = app(ReportHistory::class)->point($candidate, $previous);
                    if ($point !== null) {
                        $history[] = $point;
                        $previousIntents ??= app(ClarificationVisibility::class)->apply($context, $previous)['metrics']['intents'] ?? null;
                        $changes ??= app(ReportSections::class)->compare($previous, $payload);
                    }
                } catch (\LogicException) {
                    // A broken earlier report must not hide the selected report.
                }
            }
            $history = array_reverse($history);
            if ($signature !== null && $currentPoint !== null) {
                $history[] = $currentPoint;
            }
            $measurement = DB::table('measurements')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $payload['measurement_id'])->first(['profile_snapshot', 'profile_checksum']);
            $site = $this->siteFromProfile($measurement);
            $siteAudit = $this->siteAudit($context, $site, $reports->firstWhere('id', $selected)->created_at);
            if ($siteAudit !== null) {
                $siteAudit['criteria'] = SiteCriteria::project($siteAudit['criteria']);
            }
            $metrics = $adjusted['metrics'];
            $panel = $metrics['panel'] ?? [];
            $visibility = $metrics['visibility'] ?? [];
            $reportDate = $reports->firstWhere('id', $selected)->created_at;
            $presence = DB::table('vision_source_presence')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('updated_at', '<=', $reportDate)->pluck('presence', 'domain');
            $sections = app(ReportSections::class)->make([...$payload, 'metrics' => $metrics, 'reviewed_facts' => $reviewed]);
            foreach ($sections['sources'] as &$source) {
                if ($source['presence'] === null) {
                    $source['presence'] = match ($presence[$source['domain']] ?? null) {
                        'present' => true,
                        'absent' => false,
                        default => null,
                    };
                }
            }
            unset($source);
            $report = ['id' => $selected, 'measurement_id' => $payload['measurement_id'],
                'sections' => [...$sections, 'changes' => $changes, 'site' => $site, 'site_audit' => $siteAudit],
                'topics' => collect($metrics['intents'] ?? [])->filter(fn ($item, $key) => is_string($key) && $key !== ''
                    && is_array($item) && is_numeric($item['mention_rate'] ?? null) && ($item['mention_denominator'] ?? 0) > 0)
                    ->map(function ($item, $key) use ($previousIntents): array {
                        $before = $previousIntents[$key] ?? null;
                        $delta = is_array($before) && is_numeric($before['mention_rate'] ?? null) && ($before['mention_denominator'] ?? 0) > 0
                            ? round((float) $item['mention_rate'] - (float) $before['mention_rate'], 2) : null;

                        return ['name' => $key, 'visibility' => (float) $item['mention_rate'], 'delta' => $delta];
                    })
                    ->sortByDesc('visibility')->take(10)->values()->all(),
                'visibility_history' => $history,
                'visibility' => $visibility['macro_percent'] ?? null,
                'visibility_before' => $payload['metrics']['visibility']['macro_percent'] ?? null,
                'clarifications_applied' => $adjusted['applied'],
                'visibility_kind' => $visibility['macro_kind'] ?? 'available',
                'coverage' => $visibility['coverage'] ?? null,
                'share_of_voice' => $panel['share_of_voice'] ?? null,
                'position' => $panel['position_average'] ?? null,
                'verified_errors' => is_array($payload['reviewed_facts'] ?? null)
                    ? count(array_filter($reviewed, fn ($item) => ($item['confirmed'] ?? false) === true && ($item['classification'] ?? 'incorrect') !== 'correct')) : null,
                'providers' => collect($metrics['providers'] ?? [])->map(fn ($row, $name) => [
                    'name' => $name, 'mention_rate' => $row['mention_rate'] ?? null,
                    'coverage' => $row['coverage'] ?? null,
                ])->values()->all(),
                'questions' => count($payload['questions'] ?? []),
                'evidence_status' => $payload['evidence_status'] ?? 'not_available',
                'publication_candidates' => app(PublicationData::class)->candidatesFromPayload($payload),
            ];
        }

        return [
            'questionnaire_complete' => DB::table('visibility_quizzes')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->whereIn('status', ['submitted', 'superseded'])->exists(),
            'confirmed' => $confirmed->map(fn ($row) => ['measurement_id' => $row->measurement_id, 'confirmed_at' => $row->created_at])->all(),
            'reports' => $reports->map(fn ($row) => ['id' => $row->id, 'measurement_id' => $row->measurement_id,
                'revision' => $row->revision, 'created_at' => $row->created_at, 'measurement_at' => $row->measurement_finished_at ?? $row->measurement_created_at])->all(),
            'report' => $report,
        ];
    }
}
