<?php

namespace Tests\Feature\Equivalence;

use App\Ai\Drivers\Rejected;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Equivalence\Support\RecordedGateway;

final class RecordedToolCallsE307DTest extends TestCase
{
    public function test_offline_adapter_replays_recorded_calls_in_order_including_tool_only_responses(): void
    {
        $root = dirname(__DIR__, 2).'/fixtures/prototype/recorded-v2';
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        $count = 0;
        $toolOnly = 0;
        foreach ($manifest['cases'] as $item) {
            $case = json_decode(file_get_contents($root.'/'.$item['fixture']), true, 128, JSON_THROW_ON_ERROR);
            $gateway = new RecordedGateway($root, $case);
            foreach ($case['llm_calls'] as $call) {
                $record = json_decode(file_get_contents($root.'/'.$call['path']), true, 128, JSON_THROW_ON_ERROR);
                $response = $gateway->complete($record['request']);
                self::assertSame($record['response']['content'], $response['content'], $case['case_id']);
                self::assertSame($record['response']['toolCalls'] ?? [], $response['toolCalls'], $case['case_id']);
                $count++;
                if ($response['content'] === null) {
                    self::assertNotEmpty($response['toolCalls']);
                    $toolOnly++;
                }
            }
            try {
                $gateway->complete([]);
                self::fail('Exhausted recording was accepted.');
            } catch (Rejected) {
                self::assertTrue(true);
            }
        }
        self::assertCount(41, $manifest['cases']);
        self::assertSame(824, $count);
        self::assertSame(65, $toolOnly);
    }
}
