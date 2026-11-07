<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportSourceDistributionE818JTest extends TestCase
{
    public function test_distribution_groups_by_type_and_rounds_percent(): void
    {
        $sections = app(ReportSections::class)->make(['metrics' => ['sources' => [
            ['domain' => 'maps.test', 'answers' => 3, 'type' => 'maps'],
            ['domain' => 'market.test', 'answers' => 1, 'type' => 'marketplace'],
            ['domain' => 'unknown.test', 'answers' => 2, 'type' => null],
        ]]]);
        self::assertSame(
            [['type' => 'maps', 'answers' => 3, 'percent' => 50.0], ['type' => 'unknown', 'answers' => 2, 'percent' => 33.3], ['type' => 'marketplace', 'answers' => 1, 'percent' => 16.7]],
            $sections['source_distribution']
        );
    }

    public function test_distribution_empty_without_sources(): void
    {
        self::assertSame([], app(ReportSections::class)->make([])['source_distribution']);
    }
}
