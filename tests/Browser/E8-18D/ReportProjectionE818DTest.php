<?php

namespace Tests\Browser\E818D;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportProjectionE818DTest extends TestCase
{
    public function test_only_explicit_links_and_confirmed_facts_are_displayed(): void
    {
        $result = app(ReportSections::class)->make([
            'questions' => [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']],
            'metrics' => ['questions' => ['q1' => ['planned' => 1, 'mention_denominator' => 1, 'mentioned' => 0]]],
            'recommendations' => ['items' => [['title' => 'Связано', 'question_ids' => ['q1']], ['title' => 'Без связи']]],
            'reviewed_facts' => [
                ['text' => 'Верно', 'confirmed' => true, 'classification' => 'correct', 'source' => 'Проверка'],
                ['text' => 'Старая цена', 'confirmed' => true, 'classification' => 'outdated', 'correction' => 'Новая цена'],
                ['text' => 'Не проверено', 'confirmed' => false, 'classification' => 'correct'],
                ['text' => 'Ошибка', 'confirmed' => true],
            ],
        ]);
        self::assertSame(['Связано'], array_column($result['gaps'][0]['recommendations'], 'title'));
        self::assertSame(['correct', 'outdated', 'incorrect'], array_column($result['facts'], 'classification'));
        self::assertSame('Новая цена', $result['facts'][1]['correction']);
        self::assertSame('Проверка', $result['facts'][0]['source']);
        self::assertNull($result['facts'][2]['correction']);
    }
}
