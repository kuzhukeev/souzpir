<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PrototypeTraceTest extends TestCase
{
    public function test_all_committed_policy_traces_match_without_regenerating_fixtures(): void
    {
        $knowledge = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $knowledge[$file] = Yaml::parseFile(__DIR__.'/../../../Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml');
        }
        $policy = new Policy(new KnowledgeSnapshot($knowledge, 'fixture', 'fixture'));
        $files = glob(__DIR__.'/../../../fixtures/prototype/manual/*.json');
        $tested = 0;
        foreach ($files as $file) {
            $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $case = $fixture['input'];
            if (isset($case['scheduler_hours'])) {
                continue;
            }
            $tested++;
            $state = new LeadState(array_replace_recursive(['flags' => ['introduced' => true]], $case['initial'] ?? []));
            foreach ($case['inputs'] as $i => $input) {
                $x = $input + ['slot_updates' => [], 'client_intent' => 'answer_only', 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral', 'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false, 'prompt_injection' => false, 'closed_topic' => null, 'unknown_question' => null, 'client_claims' => [], 'refused_slots' => [], 'confidence' => 0.9];
                $policy->applyExtraction($state, $x);
                $goal = $policy->decide($state, $x);
                $trace = $fixture['trace'][$i];
                $this->assertEquals($trace['goal'], $goal->toArray(), $case['id'].' goal '.$i);
                $this->assertEquals($trace['state'], $state->toArray(), $case['id'].' state '.$i);
                $this->assertEquals($trace['outcome'], $policy->computeOutcome($state), $case['id'].' outcome '.$i);
            }
        }
        $this->assertGreaterThanOrEqual(10, $tested);
    }
}
