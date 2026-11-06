<?php

namespace Tests\Feature\Equivalence;

use App\Ai\Drivers\Rejected;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Equivalence\Support\RecordedGateway;

final class RecordedGatewayE307CTest extends TestCase
{
    public function test_recorded_calls_replay_in_order_without_cross_case_fallback(): void
    {
        $root = dirname(__DIR__, 2).'/fixtures/prototype/recorded-v2';
        $case = json_decode(file_get_contents($root.'/cases/manual.closed_topic.json'), true, 128, JSON_THROW_ON_ERROR);
        $first = json_decode(file_get_contents($root.'/'.$case['llm_calls'][0]['path']), true, 128, JSON_THROW_ON_ERROR);
        $gateway = new RecordedGateway($root, $case);
        try {
            $gateway->complete(['messages' => [], 'options' => []]);
            self::fail('Mismatched request was accepted.');
        } catch (Rejected) {
            self::assertTrue(true);
        }
        $result = $gateway->complete($first['request']);
        self::assertSame($first['response']['content'], $result['content']);
        self::assertSame($first['response']['toolCalls'], $result['toolCalls']);
        self::assertSame($first['response']['model'], $result['model']);
        $second = json_decode(file_get_contents($root.'/'.$case['llm_calls'][1]['path']), true, 128, JSON_THROW_ON_ERROR);
        $next = $gateway->complete($second['request']);
        self::assertSame($second['response']['content'], $next['content']);
        self::assertSame($second['response']['model'], $next['model']);
        try {
            $gateway->complete($second['request']);
            self::fail('Replay beyond the recorded calls was accepted.');
        } catch (Rejected) {
            self::assertTrue(true);
        }
        $foreign = $case;
        $foreign['case_id'] = 'other-brand-case';
        $other = new RecordedGateway($root, $foreign);
        $this->expectException(Rejected::class);
        $other->complete($first['request']);
    }
}
