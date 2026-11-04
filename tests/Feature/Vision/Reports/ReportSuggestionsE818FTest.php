<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\ConfirmedClarifications;
use App\Vision\Core\QuestionSuggestions;
use App\Vision\Core\ReportPreparation;
use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportSuggestionsE818FTest extends TestCase
{
    public function test_only_complete_unmentioned_questions_are_proposed_and_reviewed(): void
    {
        $snapshot = ['questions' => [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose'], ['id' => 'q2', 'text' => 'Другой', 'intent' => 'choose']],
            'metrics' => ['questions' => ['q1' => ['planned' => 2, 'mention_denominator' => 2, 'mentioned' => 0],
                'q2' => ['planned' => 2, 'mention_denominator' => 1, 'mentioned' => 0]], 'slots' => []], 'recommendations' => ['items' => []]];
        $original = $snapshot;
        $suggestions = app(QuestionSuggestions::class)->make($snapshot);
        self::assertCount(1, $suggestions);
        self::assertSame('q1', $suggestions[0]['question_id']);
        self::assertSame('Изучить ответы на этот вопрос', app(ReportSections::class)->make($snapshot)['gaps'][0]['recommendations'][0]['title']);
        $preparation = app(ReportPreparation::class);
        $items = $preparation->items($snapshot, []);
        $key = array_key_first($items);
        self::assertSame([], $preparation->report($snapshot, $items, [$key => ['decision' => 'rejected']], [])['recommendations']['items']);
        self::assertSame('q1', $preparation->report($snapshot, $items, [$key => ['decision' => 'confirmed']], [])['recommendations']['items'][0]['question_id']);
        self::assertSame($original, $snapshot);
        $snapshot['metrics']['questions']['q1']['possible'] = 1;
        self::assertSame([], app(QuestionSuggestions::class)->make($snapshot));
        $snapshot['metrics']['questions']['q1']['possible'] = 0;
        $snapshot['metrics']['questions']['q1']['mentioned'] = 1;
        self::assertSame([], app(QuestionSuggestions::class)->make($snapshot));
    }

    public function test_confirmed_error_text_requires_matching_verified_slot_and_decision(): void
    {
        $fact = ['errors' => [['claim' => 'Неверная цена', 'source' => 'Ответ модели']]];
        $slot = ['id' => 'answer', 'valid' => true, 'fact' => $fact, 'provider' => 'fake'];
        $row = (object) ['fact_id' => 'fact', 'measurement_answer_id' => 'answer', 'fact' => json_encode($fact),
            'item_key' => hash('sha256', 'facts:fact:Неверная цена'), 'decision' => 'incorrect', 'correction' => 'Цена по запросу'];
        $project = app(ConfirmedClarifications::class);
        $items = $project->project([$row], [$slot]);
        self::assertSame('Неверная цена', $items[0]['text']);
        self::assertSame('Цена по запросу', $items[0]['correction']);
        self::assertSame('Ответ модели', $items[0]['source']);
        self::assertSame([], $project->project([(object) [...(array) $row, 'decision' => 'deferred']], [$slot]));
        self::assertSame([], $project->project([(object) [...(array) $row, 'item_key' => str_repeat('0', 64)]], [$slot]));
        self::assertSame([], $project->project([$row], [[...$slot, 'valid' => false]]));
        self::assertSame([], $project->project([$row], [[...$slot, 'fact' => ['errors' => ['Другое']]]]));
        $withoutSource = ['errors' => ['Неверная цена']];
        $result = $project->project([(object) [...(array) $row, 'fact' => json_encode($withoutSource)]], [[...$slot, 'fact' => $withoutSource]]);
        self::assertNull($result[0]['source']);
    }
}
