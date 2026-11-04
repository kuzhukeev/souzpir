<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\ConfirmedClarifications;
use App\Vision\Core\ReportPreparation;
use Tests\TestCase;

final class ConfirmedClarificationsE818ETest extends TestCase
{
    public function test_only_matching_verified_and_valid_decisions_are_projected(): void
    {
        $fact = ['value' => 'Работает с 2009 года', 'source' => 'Справка'];
        $slot = ['id' => 'answer', 'fact' => $fact, 'provider' => 'gemini'];
        $base = ['fact_id' => 'fact', 'measurement_answer_id' => 'answer', 'fact' => json_encode($fact), 'item_key' => hash('sha256', 'facts:fact:'.$fact['value']), 'decision' => 'outdated', 'correction' => 'С 2010 года'];
        $project = app(ConfirmedClarifications::class);
        $result = $project->project([(object) $base], [$slot]);
        self::assertSame('outdated', $result[0]['classification']);
        self::assertSame('С 2010 года', $result[0]['correction']);
        self::assertTrue($result[0]['confirmed']);
        self::assertSame('Справка', $result[0]['source']);
        self::assertSame([], $project->project([(object) [...$base, 'item_key' => str_repeat('a', 64)]], [$slot]));
        self::assertSame([], $project->project([(object) [...$base, 'correction' => '']], [$slot]));
        self::assertSame([], $project->project([(object) [...$base, 'decision' => 'deferred']], [$slot]));
        self::assertSame([], $project->project([(object) $base], [['id' => 'answer', 'fact' => null]]));
        self::assertSame([], $project->project([(object) $base], [['id' => 'answer', 'fact' => ['value' => 'Другие данные']]]));
    }

    public function test_review_projection_keeps_only_confirmed_recommendation_links(): void
    {
        $items = [
            'a' => ['kind' => 'action', 'action' => ['title' => 'Проверено', 'question_ids' => ['q1']]],
            'b' => ['kind' => 'action', 'action' => ['title' => 'Не проверено', 'question_ids' => ['q2']]],
        ];
        $result = app(ReportPreparation::class)->report([], $items, ['a' => ['decision' => 'confirmed'], 'b' => ['decision' => 'rejected']], []);
        self::assertCount(1, $result['recommendations']['items']);
        self::assertSame(['q1'], $result['recommendations']['items'][0]['question_ids']);
    }
}
