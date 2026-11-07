<?php

namespace Tests\Feature\Vision\Publications;

use App\Vision\Core\VisionReportReader;
use App\Vision\Publications\PublicationData;
use App\Vision\Reports\SiteCriteria;
use Mockery;
use Tests\TestCase;

final class SafeCriteriaE819BTest extends TestCase
{
    public function test_only_known_criteria_and_statuses_reach_public_projection(): void
    {
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with('report')->andReturn(['questions' => [], 'metrics' => []]);
        $this->app->instance(VisionReportReader::class, $reader);
        $audit = ['readiness' => ['lower' => 20, 'upper' => 40], 'criteria' => [
            ['id' => 3, 'status' => 'pass', 'title' => 'Секрет', 'evidence' => [['url' => 'https://private.test']]],
            ['id' => 1, 'status' => 'unknown', 'explanation' => 'Личный текст'],
            ['id' => 19, 'status' => 'pass'], ['id' => 2, 'status' => 'invalid'],
        ]];
        $result = app(PublicationData::class)->fromReport('report', $audit);
        self::assertSame([['title' => 'Доступность содержимого', 'status' => 'unknown'], ['title' => 'Правила доступа роботов', 'status' => 'pass']], $result['site_criteria']);
        self::assertStringNotContainsString('private.test', json_encode($result));
        self::assertSame([], SiteCriteria::project(null));
        self::assertSame([], SiteCriteria::project([['id' => '1', 'status' => 'pass']]));
    }
}
