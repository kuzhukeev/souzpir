<?php

namespace Tests\Browser\E818B;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportSectionsTest extends TestCase
{
    public function test_only_valid_model_mentions_and_explicit_question_recommendations_are_used(): void
    {
        $result = app(ReportSections::class)->make([
            'questions' => [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']],
            'metrics' => ['questions' => ['q1' => ['planned' => 1, 'mention_denominator' => 1, 'mentioned' => 0]],
                'entities' => [['name' => 'Фирма', 'answers' => 1, 'is_brand' => false]],
                'slots' => [['valid' => true, 'provider' => 'gemini', 'mentions' => [['name' => 'Фирма']]], ['valid' => false, 'provider' => 'openai', 'mentions' => [['name' => 'Фирма']]]]],
            'recommendations' => ['items' => [['qid' => 'q1', 'title' => 'Уточнить'], ['qid' => 'q2', 'title' => 'Чужой вопрос'], ['title' => 'Без связи']]],
            'reviewed_facts' => [['text' => 'Ошибка', 'confirmed' => true], ['text' => 'Не проверено', 'confirmed' => false]],
        ]);
        self::assertSame(['gemini'], $result['competitors'][0]['models']);
        self::assertSame('Уточнить', $result['gaps'][0]['recommendations'][0]['title']);
        self::assertCount(1, $result['gaps'][0]['recommendations']);
        self::assertCount(1, $result['facts']);
        self::assertSame([], app(ReportSections::class)->make([])['competitors']);
    }
}
