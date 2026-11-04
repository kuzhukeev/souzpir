<?php

namespace Tests\Unit\Vision\Reports;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportSectionsTest extends TestCase
{
    public function test_sections_use_only_complete_observations_and_confirmed_facts(): void
    {
        $sections = app(ReportSections::class)->make([
            'questions' => [
                ['id' => 'q1', 'text' => 'Первый вопрос', 'intent' => 'choose'],
                ['id' => 'q2', 'text' => 'Неполный вопрос', 'intent' => 'price'],
            ],
            'metrics' => [
                'questions' => [
                    'q1' => ['planned' => 2, 'mention_denominator' => 2, 'mentioned' => 0],
                    'q2' => ['planned' => 2, 'mention_denominator' => 1, 'mentioned' => 0],
                ],
                'entities' => [['name' => 'Бренд', 'answers' => 3, 'is_brand' => true]],
                'sources' => [['domain' => 'example.test', 'answers' => 2, 'urls' => ['private']]],
            ],
            'reviewed_facts' => [
                ['confirmed' => true, 'text' => 'Ошибка', 'reason' => 'Устарело', 'provider' => 'Модель'],
                ['confirmed' => false, 'text' => 'Не проверено'],
            ],
            'recommendations' => ['items' => [['title' => 'Обновить данные', 'why' => 'Исправит ошибку']]],
        ]);
        self::assertSame([['text' => 'Первый вопрос', 'intent' => 'choose', 'recommendations' => [['title' => 'Изучить ответы на этот вопрос', 'why' => 'Проверьте сохранённые ответы; причины не установлены.']], 'companies' => []]], $sections['gaps']);
        self::assertCount(1, $sections['facts']);
        self::assertSame('Ошибка', $sections['facts'][0]['text']);
        self::assertSame([['domain' => 'example.test', 'answers' => 2, 'type' => null, 'presence' => null]], $sections['sources']);
        self::assertSame('Бренд', $sections['competitors'][0]['name']);
        self::assertSame('Обновить данные', $sections['actions'][0]['title']);
    }

    public function test_comparison_requires_the_same_measurement_plan(): void
    {
        $base = ['question_set_checksum' => 'same', 'providers' => ['model' => 'version'], 'runs' => 1,
            'metrics' => ['visibility' => ['macro_percent' => 20], 'entities' => [['name' => 'Старая', 'is_brand' => false]]]];
        $current = $base;
        $current['metrics']['visibility']['macro_percent'] = 30;
        $current['metrics']['entities'][] = ['name' => 'Новая', 'is_brand' => false];
        $sections = app(ReportSections::class);
        $result = $sections->compare($base, $current);
        self::assertSame(10.0, $result['rows'][0]['delta']);
        self::assertSame(['Новая'], $result['new_competitors']);
        $current['runs'] = 2;
        self::assertNull($sections->compare($base, $current));
    }
}
