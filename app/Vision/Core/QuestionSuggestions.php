<?php

namespace App\Vision\Core;

final class QuestionSuggestions
{
    public function make(array $snapshot): array
    {
        $result = [];
        foreach ($snapshot['questions'] ?? [] as $question) {
            $id = is_array($question) ? ($question['id'] ?? null) : null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $row = $snapshot['metrics']['questions'][$id] ?? null;
            if (! is_array($row) || ! is_int($row['planned'] ?? null) || $row['planned'] < 1
                || ($row['mention_denominator'] ?? null) !== $row['planned'] || ($row['mentioned'] ?? null) !== 0
                || ($row['possible'] ?? 0) !== 0 || ($row['unverified'] ?? 0) !== 0) {
                continue;
            }
            $existing = collect(data_get($snapshot, 'recommendations.items', []))->contains(fn ($item) => is_array($item)
                && (($item['question_id'] ?? $item['qid'] ?? null) === $id || (is_array($item['question_ids'] ?? null) && in_array($id, $item['question_ids'], true))));
            if (! $existing) {
                $result[] = ['title' => 'Изучить ответы на этот вопрос', 'why' => 'Проверьте сохранённые ответы; причины не установлены.', 'question_id' => $id];
            }
        }

        return $result;
    }
}
