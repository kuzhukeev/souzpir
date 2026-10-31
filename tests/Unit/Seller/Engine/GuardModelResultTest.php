<?php

namespace Tests\Unit\Seller\Engine;

use App\Contracts\Ai\AiResult;
use App\Seller\Engine\GuardModelResult;
use PHPUnit\Framework\TestCase;

final class GuardModelResultTest extends TestCase
{
    private function fixtureResult(?string $content, ?array $error = null, array $tools = []): AiResult
    {
        return new AiResult($content, $tools, ['promptTokens' => 1, 'completionTokens' => 1], 'fixture', 1, 0, 'synthetic-call', $error);
    }

    public function test_completed_synthetic_guard_result_preserves_donor_issues_and_json_parsing(): void
    {
        $guard = new GuardModelResult;
        self::assertSame(['status' => 'evaluated', 'verdict' => true, 'issues' => []], $guard->interpret($this->fixtureResult('{"ok":true,"issues":[]}')));
        $response = "```json\n{\"ok\":false,\"issues\":[\"один\",\"два\",\"три\",\"четыре\"]}\n```";
        self::assertSame(['status' => 'evaluated', 'verdict' => false, 'issues' => ['один', 'два', 'три']], $guard->interpret($this->fixtureResult($response)));
        self::assertSame(['status' => 'evaluated', 'verdict' => false, 'issues' => ['замечание']], $guard->interpret($this->fixtureResult('prefix {"issues":["замечание"],"ok":false} suffix')));
        self::assertSame(['status' => 'evaluated', 'verdict' => true, 'issues' => []], $guard->interpret($this->fixtureResult('{"ok":true,"issues":["игнорируется как у донора"]}')));
        self::assertSame(['status' => 'evaluated', 'verdict' => false, 'issues' => []], $guard->interpret($this->fixtureResult('{"ok":false,"issues":[]}')));
        self::assertSame(['status' => 'evaluated', 'verdict' => false, 'issues' => ['']], $guard->interpret($this->fixtureResult('{"ok":false,"issues":[""]}')));
    }

    public function test_pending_and_unknown_outcomes_never_become_completed_check_or_fallback(): void
    {
        $guard = new GuardModelResult;
        foreach (['in_progress', 'provider_uncertain', 'cost_unknown', 'unknown-secret-error'] as $code) {
            $result = $guard->interpret($this->fixtureResult('{"ok":true,"issues":[]}', ['code' => $code, 'detail' => 'private secret']));
            self::assertSame(['status' => 'blocked', 'verdict' => null, 'issues' => []], $result);
            self::assertStringNotContainsString($code, json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertSame(['status' => 'unavailable', 'verdict' => null, 'issues' => []], $guard->interpret($this->fixtureResult(null, ['code' => 'empty_response'])));
    }

    public function test_unreliable_results_are_unavailable_without_private_error_leakage(): void
    {
        $guard = new GuardModelResult;
        foreach ([null, '', 'PRIVATE-SECRET {', '{"ok":true,"ok":true,"issues":[]}',
            '{"ok":false,"\\u006fk":true,"issues":[]}', '{"ok":true,"issues":[],"extra":"PRIVATE-SECRET"}',
            '{"ok":"false","issues":[]}', '{"ok":true}', str_repeat('a', 65537)] as $content) {
            $result = $guard->interpret($this->fixtureResult($content));
            self::assertSame(['status' => 'unavailable', 'verdict' => null, 'issues' => []], $result);
            self::assertStringNotContainsString('PRIVATE-SECRET', json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertSame(['status' => 'unavailable', 'verdict' => null, 'issues' => []], $guard->interpret($this->fixtureResult(null, null, [['id' => 'unexpected']])));
        self::assertSame(['status' => 'unavailable', 'verdict' => null, 'issues' => []], $guard->interpret($this->fixtureResult('{"ok":false,"issues":["PRIVATE-SECRET",false]}')));
    }

    public function test_json_object_issues_are_not_accepted_as_a_list(): void
    {
        $guard = new GuardModelResult;
        foreach (['{"ok":true,"issues":{}}', '{"ok":true,"issues":{"0":"PRIVATE-SECRET"}}'] as $content) {
            $result = $guard->interpret($this->fixtureResult($content));
            self::assertSame(['status' => 'unavailable', 'verdict' => null, 'issues' => []], $result);
        }
    }

    public function test_unknown_cost_cannot_be_treated_as_a_settled_success(): void
    {
        $guard = new GuardModelResult;
        foreach ([null, ['code' => 'empty_response']] as $error) {
            $result = new AiResult('{"ok":true,"issues":[]}', [], [], 'fixture', 1, null, 'synthetic-call', $error);
            self::assertSame(['status' => 'blocked', 'verdict' => null, 'issues' => []], $guard->interpret($result));
        }
        $invalidCost = new AiResult('{"ok":true,"issues":[]}', [], [], 'fixture', 1, -1, 'synthetic-call');
        self::assertSame(['status' => 'unavailable', 'verdict' => null, 'issues' => []], $guard->interpret($invalidCost));
    }
}
