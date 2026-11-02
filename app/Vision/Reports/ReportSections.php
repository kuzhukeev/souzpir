<?php

namespace App\Vision\Reports;

final class ReportSections
{
    public function compare(array $previous, array $current): ?array
    {
        if (($previous['question_set_checksum'] ?? null) !== ($current['question_set_checksum'] ?? null)
            || ($previous['providers'] ?? null) !== ($current['providers'] ?? null)
            || ($previous['runs'] ?? null) !== ($current['runs'] ?? null)) {
            return null;
        }
        $paths = ['Видимость' => 'metrics.visibility.macro_percent', 'Доля голоса' => 'metrics.panel.share_of_voice', 'Средняя позиция' => 'metrics.panel.position_average'];
        $rows = [];
        foreach ($paths as $label => $path) {
            $before = data_get($previous, $path);
            $after = data_get($current, $path);
            if (is_numeric($before) && is_numeric($after)) {
                $rows[] = ['label' => $label, 'before' => $before, 'after' => $after, 'delta' => round($after - $before, 2)];
            }
        }
        $old = collect(data_get($previous, 'metrics.entities', []))->pluck('name')->all();
        $newCompetitors = collect(data_get($current, 'metrics.entities', []))->filter(fn ($item) => ! ($item['is_brand'] ?? false) && ! in_array($item['name'], $old, true))->pluck('name')->values()->all();

        $appeared = [];
        $disappeared = [];
        foreach ($current['questions'] ?? [] as $question) {
            $id = $question['id'];
            $before = data_get($previous, 'metrics.questions.'.$id);
            $after = data_get($current, 'metrics.questions.'.$id);
            if (! is_array($before) || ! is_array($after) || ($before['planned'] ?? 0) < 1 || ($after['planned'] ?? 0) < 1
                || ($before['mention_denominator'] ?? 0) !== $before['planned'] || ($after['mention_denominator'] ?? 0) !== $after['planned']) {
                continue;
            }
            if (($before['mentioned'] ?? 0) === 0 && ($after['mentioned'] ?? 0) > 0) {
                $appeared[] = $question['text'];
            } elseif (($before['mentioned'] ?? 0) > 0 && ($after['mentioned'] ?? 0) === 0) {
                $disappeared[] = $question['text'];
            }
        }

        return ['rows' => $rows, 'new_competitors' => $newCompetitors, 'appeared' => $appeared, 'disappeared' => $disappeared];
    }

    public function make(array $payload): array
    {
        $metrics = $payload['metrics'] ?? [];
        $questions = collect($payload['questions'] ?? [])->keyBy('id');
        $gaps = [];
        foreach ($metrics['questions'] ?? [] as $id => $row) {
            $question = $questions->get($id);
            if (! $question || ($row['planned'] ?? 0) < 1 || ($row['mention_denominator'] ?? 0) !== $row['planned'] || ($row['mentioned'] ?? 0) !== 0) {
                continue;
            }
            $gaps[] = ['text' => $question['text'], 'intent' => $question['intent']];
        }

        return [
            'competitors' => collect($metrics['entities'] ?? [])->take(10)->map(fn ($item) => [
                'name' => $item['name'], 'answers' => $item['answers'], 'is_brand' => $item['is_brand'],
            ])->values()->all(),
            'gaps' => $gaps,
            'facts' => collect($payload['reviewed_facts'] ?? [])->filter(fn ($item) => ($item['confirmed'] ?? false) === true)
                ->map(fn ($item) => ['text' => $item['text'], 'reason' => $item['reason'] ?? '', 'provider' => $item['provider'] ?? ''])->values()->all(),
            'sources' => collect($metrics['sources'] ?? [])->take(10)->map(fn ($item) => [
                'domain' => $item['domain'], 'answers' => $item['answers'],
            ])->values()->all(),
            'actions' => collect(data_get($payload, 'recommendations.items', []))->take(3)->map(fn ($item) => [
                'title' => $item['title'] ?? '', 'why' => $item['why'] ?? '',
            ])->filter(fn ($item) => $item['title'] !== '')->values()->all(),
        ];
    }
}
