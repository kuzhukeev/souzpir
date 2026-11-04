<?php

namespace Tests\Browser\E818C;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportDetailsTest extends TestCase
{
    public function test_only_confirmed_answer_companies_and_saved_rates_are_shown(): void
    {
        $result = app(ReportSections::class)->make(['questions' => [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']],
            'metrics' => ['questions' => ['q1' => ['planned' => 1, 'mention_denominator' => 1, 'mentioned' => 0]],
                'entities' => [['name' => 'Альфа', 'answers' => 1, 'is_brand' => false]],
                'intents' => ['choose' => ['entities' => [['name' => 'Альфа', 'frequency' => 60]]]],
                'slots' => [['valid' => true, 'qid' => 'q1', 'provider' => 'gemini', 'mentions' => [['name' => 'Альфа'], ['name' => 'Сомнительная', 'relevance' => 'candidate']]], ['valid' => false, 'qid' => 'q1', 'mentions' => [['name' => 'Непроверенная']]]]],
            'reviewed_facts' => [['text' => 'Не подтверждено', 'confirmed' => false]]]);
        self::assertSame(['Альфа'], $result['gaps'][0]['companies']);
        self::assertSame(['choose'], $result['competitors'][0]['strongest']);
        self::assertSame([], $result['facts']);
    }
}
