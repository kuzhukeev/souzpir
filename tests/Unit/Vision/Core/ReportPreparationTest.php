<?php

namespace Tests\Unit\Vision\Core;

use App\Vision\Core\ReportPreparation;
use App\Vision\Core\Tenant;
use App\Vision\Core\VisibilityMetrics;
use PHPUnit\Framework\TestCase;

final class ReportPreparationTest extends TestCase
{
    public function test_only_reviewed_actions_and_confirmed_source_evidence_survive(): void
    {
        $service = new ReportPreparation(new Tenant, new VisibilityMetrics);
        $snapshot = ['metrics' => ['slots' => [
            ['id' => 'a', 'provider' => 'p', 'valid' => true, 'fact' => ['suspected_errors' => [['claim' => 'сомнительное', 'reason' => 'проверить']]]],
            ['id' => 'b', 'provider' => 'p', 'valid' => false, 'fact' => ['suspected_errors' => ['нельзя учитывать']]],
        ]], 'recommendations' => ['items' => [['title' => 'Подтвердить', 'why' => 'основание'], ['title' => 'Отклонить']]]];
        $sources = [['domain' => 'example.test', 'type' => 'media', 'type_origin' => 'llm', 'presence_status' => 'not_found']];
        $items = $service->items($snapshot, $sources);
        self::assertCount(5, $items);
        $fact = $items['fact:a:0'];
        $fact['confirmed'] = true; // A caller cannot override the decision.
        $items['fact:a:0'] = $fact;
        $report = $service->report($snapshot, $items, ['action:0' => ['decision' => 'confirmed']], $sources);
        self::assertFalse($report['reviewed_facts'][0]['confirmed']);
        self::assertCount(1, $report['recommendations']['items']);
        self::assertSame('Подтвердить', $report['recommendations']['items'][0]['title']);
        self::assertSame('unknown', $report['reviewed_sources'][0]['presence_status']);
        self::assertSame('other', $report['reviewed_sources'][0]['type']);
    }
}
