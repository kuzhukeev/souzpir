<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportRecommendationsE822FTest extends TestCase
{
    public function test_recommendation_uses_only_saved_priority_steps_and_known_questions(): void
    {
        $payload = ['questions' => [['id' => 'q1', 'text' => 'Вопрос 1'], ['id' => 'q2', 'text' => 'Вопрос 2']],
            'recommendations' => ['items' => [
                ['title' => 'Условия', 'priority' => 'Высокий', 'steps' => ['Проверить', 'Изменить'], 'question_ids' => ['q1', 'q1', 'q2', 'unknown']],
                ['title' => 'Другое'],
            ]]];
        $actions = app(ReportSections::class)->make($payload)['actions'];
        self::assertSame('Связано с вопросами отчёта: 2.', $actions[0]['why']);
        self::assertSame('Высокий', $actions[0]['priority']);
        self::assertSame(['Проверить', 'Изменить'], $actions[0]['steps']);
        self::assertSame('', $actions[1]['why']);
        self::assertNull($actions[1]['priority']);
        self::assertSame([], $actions[1]['steps']);
    }
}
