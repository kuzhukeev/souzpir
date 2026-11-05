<?php

namespace Tests\Feature\Vision\Competitors;

use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\VisibilityMetrics;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HistoricalPositionsE820TTest extends TenancyTestCase
{
    public function test_missing_aggregate_is_derived_from_confirmed_slots_without_modifying_snapshot(): void
    {
        $slot = fn ($question, $complete, $position) => ['provider' => 'fake', 'question_n' => $question, 'run_no' => 1, 'intent' => 'choose', 'status' => 'ok', 'fact' => ['brand_mentioned' => false, 'mention_status' => 'not_mentioned'], 'mentions_complete' => $complete, 'mentions' => [['name' => 'Альфа', 'position' => $position]]];
        $metrics = app(VisibilityMetrics::class)->calculate([$slot(1, true, 2), $slot(2, false, 9)], ['fake'], 2, 1);
        unset($metrics['entities'][0]['position'], $metrics['entities'][0]['positions_by_provider'], $metrics['entities'][0]['positions_by_intent']);
        $snapshot = ['providers' => ['fake' => 'test/model'], 'questions' => [['id' => 'q1'], ['id' => 'q2']], 'runs' => 1, 'metrics' => $metrics];
        $before = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $result = app(SnapshotView::class)->metrics($snapshot, []);
        self::assertSame(['average' => 2.0, 'count' => 1], $result['entities'][0]['position']);
        self::assertSame(['average' => 2.0, 'count' => 1], $result['entities'][0]['positions_by_provider']['fake']);
        self::assertSame($before, json_encode($snapshot, JSON_THROW_ON_ERROR));
        $snapshot['metrics']['entities'][0]['position'] = ['average' => 7.0, 'count' => 9];
        self::assertSame(['average' => 7.0, 'count' => 9], app(SnapshotView::class)->metrics($snapshot, [])['entities'][0]['position']);
        $snapshot['metrics']['slots'][0]['mentions'][0]['position'] = null;
        unset($snapshot['metrics']['entities'][0]['position']);
        self::assertArrayNotHasKey('position', app(SnapshotView::class)->metrics($snapshot, [])['entities'][0]);
        $snapshot['metrics']['slots'] = [['invalid' => true]];
        self::assertSame($snapshot['metrics'], app(SnapshotView::class)->metrics($snapshot, []));
    }
}
