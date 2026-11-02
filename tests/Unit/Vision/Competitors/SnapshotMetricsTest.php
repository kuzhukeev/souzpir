<?php

namespace Tests\Unit\Vision\Competitors;

use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\VisibilityMetrics;
use Tests\TestCase;

final class SnapshotMetricsTest extends TestCase
{
    public function test_merge_deduplicates_mentions_within_one_answer(): void
    {
        $slot = ['provider' => 'a', 'question_n' => 1, 'run_no' => 1, 'qid' => 'q1', 'intent' => 'choose', 'status' => 'ok',
            'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => false, 'brand_recommended' => false],
            'mentions' => [['name' => 'Альфа', 'role' => null], ['name' => 'Альфа ООО', 'role' => null]], 'sources' => []];
        $original = app(VisibilityMetrics::class)->calculate([$slot], ['a'], 1, 1);
        $snapshot = ['providers' => ['a' => 'model'], 'questions' => [['id' => 'q1']], 'runs' => 1, 'metrics' => $original];
        self::assertCount(2, $original['entities']);
        $merged = app(SnapshotView::class)->metrics($snapshot, ['Альфа ООО' => 'Альфа']);
        self::assertCount(1, $merged['entities']);
        self::assertSame('Альфа', $merged['entities'][0]['name']);
        self::assertSame(1, $merged['entities'][0]['answers']);
        self::assertSame($original['visibility'], $merged['visibility']);
    }
}
