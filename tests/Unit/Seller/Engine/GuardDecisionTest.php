<?php

namespace Tests\Unit\Seller\Engine;

use App\Contracts\Ai\AiResult;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\GuardModelResult;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class GuardDecisionTest extends TestCase
{
    public function test_disabled_model_retains_synthetic_donor_golden_decisions(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/guard-prototype-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
        $guard = new DeterministicGuard(new KnowledgeSnapshot($fixture['knowledge'], 'synthetic-release', 'synthetic-checksum'));
        $decision = new GuardDecision;
        foreach ($fixture['cases'] as $case) {
            $goal = new Goal($case['kind']);
            $deterministic = $guard->check($case['reply'], $goal, new LeadState($case['state']), $case['context']);
            self::assertFalse($decision->requiresModel($deterministic, $goal, false), $case['name']);
            self::assertSame(['status' => 'completed', ...$case['expected']], $decision->finish($deterministic, $goal, false), $case['name']);
        }
    }

    public function test_model_is_requested_only_for_donor_goals_without_deterministic_issues(): void
    {
        $decision = new GuardDecision;
        foreach (['answer_and_ask', 'handle_objection', 'unknown_fact', 'closed_topic'] as $kind) {
            self::assertTrue($decision->requiresModel($this->clear(), new Goal($kind), true));
            self::assertSame('requires_model', $decision->finish($this->clear(), new Goal($kind), true)['status']);
            self::assertNull($decision->finish($this->clear(), new Goal($kind), true)['verdict']);
        }
        foreach (['answer_only', 'handoff', 'silent', 'spam_silent'] as $kind) {
            self::assertFalse($decision->requiresModel($this->clear(), new Goal($kind), true));
            self::assertSame(['status' => 'completed', ...$this->clear()], $decision->finish($this->clear(), new Goal($kind), true));
        }
        $blocked = ['verdict' => 'regenerate', 'issues' => ['замечание'], 'warnings' => ['предупреждение'], 'words' => 5, 'questions' => 1];
        self::assertFalse($decision->requiresModel($blocked, new Goal('answer_and_ask'), true));
        self::assertSame(['status' => 'completed', ...$blocked], $decision->finish($blocked, new Goal('answer_and_ask'), true));
    }

    public function test_valid_model_result_merges_with_original_warning_and_counts(): void
    {
        $decision = new GuardDecision;
        $goal = new Goal('answer_and_ask');
        $guard = new GuardModelResult;
        $positive = $guard->interpret($this->fixtureResult('{"ok":true,"issues":[]}'));
        self::assertSame(['status' => 'completed', ...$this->clear()], $decision->finish($this->clear(), $goal, true, $positive));
        $negative = $guard->interpret($this->fixtureResult('{"ok":false,"issues":["один","два","три","четыре"]}'));
        self::assertSame(['status' => 'completed', 'verdict' => 'regenerate', 'issues' => ['один', 'два', 'три'],
            'warnings' => ['предупреждение'], 'words' => 5, 'questions' => 1], $decision->finish($this->clear(), $goal, true, $negative));
    }

    public function test_unknown_pending_empty_and_unexplained_negative_never_become_ok(): void
    {
        $goal = new Goal('unknown_fact');
        $decision = new GuardDecision;
        $guard = new GuardModelResult;
        foreach ([
            [$this->fixtureResult(null, ['code' => 'in_progress']), 'blocked'],
            [$this->fixtureResult(null, ['code' => 'provider_uncertain']), 'blocked'],
            [$this->fixtureResult(null, ['code' => 'empty_response']), 'unavailable'],
            [$this->fixtureResult('{"ok":false,"issues":[]}'), 'unavailable'],
            [$this->fixtureResult('{"ok":true,"issues":[],"extra":1}'), 'unavailable'],
        ] as [$response, $expected]) {
            $result = $decision->finish($this->clear(), $goal, true, $guard->interpret($response));
            self::assertSame($expected, $result['status']);
            self::assertNull($result['verdict']);
            self::assertSame([], $result['issues']);
            self::assertSame(['предупреждение'], $result['warnings']);
            self::assertSame(5, $result['words']);
            self::assertSame(1, $result['questions']);
        }
    }

    public function test_malformed_issues_and_unknown_cost_do_not_become_success(): void
    {
        $guard = new GuardModelResult;
        $decision = new GuardDecision;
        foreach ([
            [$this->fixtureResult('{"ok":true,"issues":{}}'), 'unavailable'],
            [$this->fixtureResult('{"ok":true,"issues":{"0":"synthetic"}}'), 'unavailable'],
            [new AiResult('{"ok":true,"issues":[]}', [], [], 'fixture', 1, null, 'synthetic-call'), 'blocked'],
        ] as [$response, $status]) {
            $result = $decision->finish($this->clear(), new Goal('answer_and_ask'), true, $guard->interpret($response));
            self::assertSame($status, $result['status']);
            self::assertNull($result['verdict']);
            self::assertSame([], $result['issues']);
        }
    }

    public function test_inconsistent_or_untrusted_inputs_are_rejected_without_data_in_exception(): void
    {
        $valid = $this->clear();
        foreach ([
            [[...$valid, 'issues' => ['synthetic-private-witness']], new Goal('answer_and_ask'), true, null],
            [[...$valid, 'words' => -1], new Goal('answer_and_ask'), true, null],
            [[...$valid, 'warnings' => ["\xff"]], new Goal('answer_and_ask'), true, null],
            [$valid, new Goal('answer_and_ask'), true, ['status' => 'evaluated', 'verdict' => true, 'issues' => ['synthetic-private-witness']]],
            [$valid, new Goal('answer_and_ask'), true, ['status' => 'blocked', 'verdict' => false, 'issues' => []]],
            [$valid, new Goal('answer_and_ask'), true, ['status' => 'evaluated', 'verdict' => false, 'issues' => array_fill(0, 4, 'x')]],
            [$valid, new Goal('silent'), false, ['status' => 'evaluated', 'verdict' => true, 'issues' => []]],
        ] as [$deterministic, $goal, $enabled, $model]) {
            $this->assertRejected($deterministic, $goal, $enabled, $model);
        }
    }

    private function clear(): array
    {
        return ['verdict' => 'ok', 'issues' => [], 'warnings' => ['предупреждение'], 'words' => 5, 'questions' => 1];
    }

    private function fixtureResult(?string $content, ?array $error = null): AiResult
    {
        return new AiResult($content, [], [], 'fixture', 1, 0, 'synthetic-call', $error);
    }

    private function assertRejected(#[SensitiveParameter] array $deterministic, #[SensitiveParameter] Goal $goal, bool $enabled, #[SensitiveParameter] ?array $model): void
    {
        try {
            (new GuardDecision)->finish($deterministic, $goal, $enabled, $model);
            self::fail('Inconsistent guard input accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Guard decision could not be safely prepared.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-witness', (string) $error);
            self::assertStringNotContainsString('synthetic-private-witness', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }
}
