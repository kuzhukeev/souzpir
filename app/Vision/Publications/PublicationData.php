<?php

namespace App\Vision\Publications;

use App\Tenancy\TenantContext;
use App\Vision\Core\ReportPreparation;
use App\Vision\Core\VisionReportReader;
use App\Vision\Reports\SiteCriteria;
use Illuminate\Validation\ValidationException;

final class PublicationData
{
    public function fromReport(string $reportId, ?array $siteAudit = null, ?array $confirmedActions = null, ?TenantContext $tenant = null, ?object $report = null): array
    {
        $source = app(VisionReportReader::class)->read($reportId);

        $entities = collect(data_get($source, 'metrics.entities', []))->filter(fn ($row) => is_array($row) && ($row['is_brand'] ?? false) === false)
            ->map(fn ($row) => ['name' => $this->publicText($row['name'] ?? null), 'answers' => max(0, (int) ($row['answers'] ?? 0))])
            ->filter(fn ($row) => $row['name'] !== null)->sortByDesc('answers')->take(5)->values()->all();
        $facts = is_array($source['reviewed_facts'] ?? null) ? $source['reviewed_facts'] : [];
        if ($confirmedActions === null) {
            $actions = collect(data_get($source, 'recommendations.items', []))->map(fn ($row) => is_array($row) ? $this->publicText($row['title'] ?? null) : null)
                ->filter()->take(3)->values()->all();
            $actionQuestionIds = [];
            $actionDetails = [];
        } else {
            $candidates = collect($this->candidatesFromPayload($source))->keyBy('key');
            if (count($confirmedActions) !== count(array_unique($confirmedActions))
                || collect($confirmedActions)->contains(fn ($key) => ! $candidates->has($key))) {
                throw ValidationException::withMessages(['confirmed_actions' => 'Обновите список рекомендаций и повторите выбор.']);
            }
            $reviewed = collect($confirmedActions)->map(fn ($key) => ['title' => $this->publicText($candidates[$key]['title']),
                'question_id' => $candidates[$key]['question_id'], 'priority' => $candidates[$key]['priority'] ?? null, 'steps' => $candidates[$key]['steps'] ?? []])->filter(fn ($row) => $row['title'] !== null)->take(3)->values();
            $actions = $reviewed->pluck('title')->all();
            $actionQuestionIds = $reviewed->pluck('question_id')->all();
            $actionDetails = $reviewed->map(fn ($item) => ['priority' => $item['priority'], 'steps' => $item['steps']])->all();
        }
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
            'reviewed_facts' => count($facts), 'actions' => $actions, 'action_details' => $actionDetails, 'action_question_ids' => $actionQuestionIds, 'site_readiness' => $site,
            'site_criteria' => SiteCriteria::project($siteAudit['criteria'] ?? []),
            'history' => $tenant && $report ? app(PublicMetricHistory::class)->build($tenant, $report, $source) : []];
    }

    public function candidatesFromPayload(array $source): array
    {
        $questionIds = collect($source['questions'] ?? [])->pluck('id')->filter()->all();

        return collect(app(ReportPreparation::class)->items($source, []))
            ->filter(fn ($item) => ($item['kind'] ?? null) === 'action'
                && in_array($item['action']['question_id'] ?? null, $questionIds, true)
                && is_string($item['title'] ?? null) && trim($item['title']) !== '')
            ->map(function ($item, $key): array {
                $priority = $this->publicText($item['action']['priority'] ?? null);
                $steps = collect(is_array($item['action']['steps'] ?? null) ? $item['action']['steps'] : [])
                    ->map(fn ($step) => $this->publicText($step))->filter()->take(3)->values()->all();

                return ['key' => $key, 'title' => $item['title'],
                    'why' => is_string($item['text'] ?? null) ? $item['text'] : '',
                    'question_id' => $item['action']['question_id'],
                    ...($priority !== null ? ['priority' => $priority] : []),
                    ...($steps !== [] ? ['steps' => $steps] : [])];
            })
            ->values()->all();
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
