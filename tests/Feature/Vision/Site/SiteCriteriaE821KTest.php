<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Reports\SiteCriteria;
use Tests\TestCase;

final class SiteCriteriaE821KTest extends TestCase
{
    public function test_report_shows_five_saved_known_criteria_without_exposing_evidence(): void
    {
        $criteria = [];
        for ($id = 1; $id <= 16; $id++) {
            $criteria[] = ['id' => $id, 'status' => $id === 3 ? 'unknown' : 'pass', 'evidence' => [['url' => 'https://private.test']]];
        }
        $projected = SiteCriteria::project($criteria);
        self::assertCount(5, $projected);
        self::assertSame('Правила доступа роботов', $projected[2]['title']);
        self::assertSame('unknown', $projected[2]['status']);
        self::assertStringNotContainsString('private.test', json_encode($projected));
        self::assertSame([], SiteCriteria::project([['id' => 17, 'status' => 'pass'], ['id' => 1, 'status' => 'invalid']]));
    }
}
