<?php

namespace Tests\Feature\Equivalence;

use PHPUnit\Framework\TestCase;

final class RecordedCallsIntegrityTest extends TestCase
{
    public function test_all_recorded_calls_are_present_and_match_their_digests(): void
    {
        $root = dirname(__DIR__, 2).'/fixtures/prototype/recorded-v2';
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        $paths = [];
        foreach ($manifest['cases'] as $case) {
            $fixture = json_decode(file_get_contents($root.'/'.$case['fixture']), true, 128, JSON_THROW_ON_ERROR);
            foreach ($fixture['llm_calls'] as $call) {
                self::assertStringStartsWith('llm/', $call['path']);
                self::assertFileExists($root.'/'.$call['path']);
                self::assertSame($call['sha256'], hash_file('sha256', $root.'/'.$call['path']), $case['id']);
                $paths[] = $call['path'];
            }
        }
        self::assertCount(824, $paths);
        self::assertCount(824, array_unique($paths));
    }
}
