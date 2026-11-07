<?php

namespace App\Vision\Publications;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Copy only comparable, confirmed aggregate metrics into the publication snapshot. */
final class PublicMetricHistory
{
    public function build(TenantContext $tenant, object $report, array $current): array
    {
        $signature = $this->signature($current);
        if ($signature === null) {
            return [];
        }
        $confirmed = DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->select('measurement_id');
        $previous = DB::table('vision_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->whereIn('measurement_id', $confirmed)->where('measurement_id', '!=', $report->measurement_id)
            ->where(function ($query) use ($report): void {
                $query->where('created_at', '<', $report->created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $report->created_at)->where('id', '<', $report->id));
            })->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get(['id', 'measurement_id', 'created_at']);
        $rows = [];
        $seen = [];
        foreach ($previous as $candidate) {
            if (isset($seen[$candidate->measurement_id])) {
                continue;
            }
            $seen[$candidate->measurement_id] = true;
            try {
                $payload = app(VisionReportReader::class)->read($candidate->id);
            } catch (LogicException) {
                continue;
            }
            if ($this->signature($payload) !== $signature) {
                continue;
            }
            $rows[] = $this->point($candidate->created_at, $payload);
            if (count($rows) === 5) {
                break;
            }
        }
        if ($rows === []) {
            return [];
        }

        return [...array_reverse($rows), $this->point($report->created_at, $current)];
    }

    private function signature(array $source): ?string
    {
        if (! is_string($source['question_set_checksum'] ?? null) || $source['question_set_checksum'] === ''
            || ! is_array($source['providers'] ?? null) || $source['providers'] === [] || ! is_int($source['runs'] ?? null) || $source['runs'] < 1) {
            return null;
        }

        return CanonicalJson::hash(['questions' => $source['question_set_checksum'], 'providers' => $source['providers'], 'runs' => $source['runs']]);
    }

    private function point(string $date, array $source): array
    {
        $metrics = app(PublicationData::class)->fromPayload($source);

        $facts = $source['reviewed_facts'] ?? null;
        $errors = is_array($facts) ? count(array_filter($facts, fn ($item) => is_array($item)
            && ($item['confirmed'] ?? false) === true && ($item['classification'] ?? 'incorrect') !== 'correct')) : null;

        return ['date' => substr($date, 0, 10), 'visibility' => $metrics['visibility'], 'coverage' => $metrics['coverage'],
            'share_of_voice' => $metrics['share_of_voice'], 'position' => $metrics['position'], 'verified_errors' => $errors];
    }
}
