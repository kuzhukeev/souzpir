<?php

namespace App\Vision\Publications;

use App\Vision\Core\VisionReportReader;

final class PublicationData
{
    public function fromReport(string $reportId, ?array $siteAudit = null): array
    {
        $source = app(VisionReportReader::class)->read($reportId);

        $entities = collect(data_get($source, 'metrics.entities', []))->filter(fn ($row) => is_array($row) && ($row['is_brand'] ?? false) === false)
            ->map(fn ($row) => ['name' => $this->publicText($row['name'] ?? null), 'answers' => max(0, (int) ($row['answers'] ?? 0))])
            ->filter(fn ($row) => $row['name'] !== null)->sortByDesc('answers')->take(5)->values()->all();
        $facts = is_array($source['reviewed_facts'] ?? null) ? $source['reviewed_facts'] : [];
        $actions = collect(data_get($source, 'recommendations.items', []))->map(fn ($row) => is_array($row) ? $this->publicText($row['title'] ?? null) : null)
            ->filter()->take(3)->values()->all();
        $site = $siteAudit['readiness'] ?? null;
        if (is_array($site)) {
            $site = ['lower' => $this->number($site['lower'] ?? null), 'upper' => $this->number($site['upper'] ?? null)];
            if ($site['lower'] === null || $site['upper'] === null) {
                $site = null;
            }
        } else {
            $site = $this->number($site);
        }

        return [...$this->fromPayload($source), 'competitors' => $entities,
            'confirmed_facts' => count(array_filter($facts, fn ($item) => is_array($item) && ($item['confirmed'] ?? false) === true)),
            'reviewed_facts' => count($facts), 'actions' => $actions, 'site_readiness' => $site];
    }

    public function fromPayload(array $source): array
    {
        $metrics = $source['metrics'] ?? [];
        $visibility = $metrics['visibility'] ?? [];
        $panel = $metrics['panel'] ?? [];

        return [
            'visibility' => $this->number($visibility['macro_percent'] ?? null),
            'coverage' => $this->number($visibility['coverage'] ?? null),
            'share_of_voice' => $this->number($panel['share_of_voice'] ?? null),
            'position' => $this->number($panel['position_average'] ?? null),
            'questions' => count($source['questions'] ?? []),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 2) : null;
    }

    private function publicText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/https?:\/\/\S+|[\w.+-]+@[\w.-]+\.[a-z]{2,}|[\x00-\x1f\x7f]/iu', '', strip_tags($value));
        $value = preg_replace('/(?<!\w)\+?\d[\d\s().-]{8,}\d(?!\w)/u', '', (string) $value);
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 180);
    }
}
