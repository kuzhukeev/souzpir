<?php

namespace Tests\Feature\Equivalence;

use PHPUnit\Framework\TestCase;

final class RecordedBaselineInventoryTest extends TestCase
{
    public function test_all_t01_cases_have_recorded_offline_replay_evidence(): void
    {
        $root = dirname(__DIR__, 2).'/fixtures/prototype';
        $original = json_decode(file_get_contents($root.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $recorded = json_decode(file_get_contents($root.'/recorded-v2/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $expected = array_column($original['cases'], 'id');
        $actual = array_column($recorded['cases'], 'id');
        sort($expected);
        sort($actual);
        self::assertCount(41, $expected);
        self::assertSame($expected, $actual);
        self::assertSame(29, $recorded['coverage']['personas']);
        self::assertSame(12, $recorded['coverage']['manual']);
        self::assertSame(0, $recorded['coverage']['missing']);

        foreach ($recorded['cases'] as $case) {
            $path = $root.'/recorded-v2/'.$case['fixture'];
            self::assertSame($case['fixture_sha256'], hash_file('sha256', $path), $case['id']);
            $fixture = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
            self::assertSame($case['id'], $fixture['case_id']);
            self::assertSame(2, $fixture['replay']['passes']);
            self::assertSame(0, $fixture['replay']['network_calls']);
            self::assertSame([$fixture['replay']['trace_sha256'], $fixture['replay']['trace_sha256']], $fixture['replay']['run_trace_sha256']);
        }
        self::assertFalse($recorded['equivalence_claim']);
    }
}
