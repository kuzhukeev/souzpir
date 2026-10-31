<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Expected results were generated once with the read-only Laravel prototype's
 * App\Agent\Guard using only the synthetic knowledge and inputs in this fixture.
 * Neither the donor installation nor external services are needed to run it.
 */
final class GuardPrototypeGoldenTest extends TestCase
{
    public function test_deterministic_guard_matches_synthetic_prototype_golden_cases(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/guard-prototype-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
        $guard = new DeterministicGuard(new KnowledgeSnapshot($fixture['knowledge'], 'synthetic-release', 'synthetic-checksum'));

        foreach ($fixture['cases'] as $case) {
            $actual = $guard->check($case['reply'], new Goal($case['kind']), new LeadState($case['state']), $case['context']);
            self::assertSame($case['expected'], $actual, $case['name']);
        }
    }
}
