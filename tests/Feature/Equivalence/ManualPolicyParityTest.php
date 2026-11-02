<?php

namespace Tests\Feature\Equivalence;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ManualPolicyParityTest extends TestCase
{
    public function test_deterministic_policy_replays_manual_t01_traces(): void
    {
        $root = dirname(__DIR__, 2);
        $knowledge = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $knowledge[$file] = Yaml::parseFile($root.'/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml');
        }
        $policy = new Policy(new KnowledgeSnapshot($knowledge, 'fixture', 'fixture'));
        $files = glob($root.'/fixtures/prototype/manual/*.json');
        self::assertCount(12, $files);
        $replayed = 0;
        foreach ($files as $file) {
            $fixture = json_decode(file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
            if (! str_contains($fixture['layer'], 'Policy')) {
                continue;
            }
            $state = new LeadState($fixture['input']['initial'] ?? []);
            foreach ($fixture['trace'] as $turn) {
                $extraction = $turn['extraction'];
                $policy->applyExtraction($state, $extraction);
                $goal = $policy->decide($state, $extraction);
                self::assertEquals($turn['goal'], $goal->toArray(), $fixture['case_id']);
                self::assertSame($turn['outcome'], $policy->computeOutcome($state), $fixture['case_id']);
                $replayed++;
            }
        }
        self::assertSame(27, $replayed);
    }
}
