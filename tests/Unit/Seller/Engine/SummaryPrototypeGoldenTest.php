<?php

namespace Tests\Unit\Seller\Engine;

use App\Contracts\Ai\AiResult;
use App\Seller\Engine\SummaryProjection;
use App\Seller\Engine\SummaryUnavailable;
use App\Seller\Engine\SummaryUncertain;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Synthetic outputs captured from the read-only Laravel prototype; no AI service is called. */
final class SummaryPrototypeGoldenTest extends TestCase
{
    private function cases(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/summary-prototype-golden.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_request_and_ordinary_results_match_the_prototype_golden_cases(): void
    {
        $projection = new SummaryProjection;
        $fixtures = $this->cases();
        $this->assertCount(6, $fixtures);
        foreach ($fixtures as $fixture) {
            $expected = $fixture['request'];
            $request = $projection->request($expected['messages'][0]['content'], $fixture['history'], $expected['options']['model'], 'v1', str_repeat('a', 64));
            $this->assertSame($expected['messages'], $request->messages, $fixture['id']);
            $this->assertSame($expected['options']['model'], $request->model, $fixture['id']);
            $this->assertSame($expected['options']['temperature'], $request->temperature, $fixture['id']);
            $this->assertSame($expected['options']['max_tokens'], $request->maxTokens, $fixture['id']);

            if ($fixture['error'] === null && $fixture['content'] !== null && trim($fixture['content']) !== '') {
                $result = new AiResult($fixture['content'], [], [], 'fake-model', 0, 0, 'fake-call');
                $this->assertSame($fixture['summary'], $projection->result($result, $fixture['previous']), $fixture['id']);
            }
        }
    }

    public function test_ambiguous_empty_success_and_provider_failure_are_deliberately_fail_closed(): void
    {
        $projection = new SummaryProjection;
        $fixtures = array_column($this->cases(), null, 'id');
        foreach (['empty-success', 'null-success'] as $id) {
            $fixture = $fixtures[$id];
            $this->assertSame('', $fixture['summary'], 'The prototype accepts a blank success.');
            try {
                $projection->result(new AiResult($fixture['content'], [], [], 'fake-model', 0, 0, 'fake-call'), $fixture['previous']);
                $this->fail('An ambiguous blank success must not become a completed summary.');
            } catch (SummaryUnavailable $error) {
                $this->assertNull($error->getPrevious());
            }
        }

        $failure = $fixtures['provider-failure'];
        $this->assertSame($failure['previous'], $failure['summary'], 'The prototype falls back on any provider error.');
        try {
            $projection->result(new AiResult(null, [], [], 'fake-model', 0, 0, 'fake-call', ['code' => $failure['error']]), $failure['previous']);
            $this->fail('An unknown outcome must not become a completed summary.');
        } catch (SummaryUncertain $error) {
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_exact_history_count_and_combined_byte_boundaries(): void
    {
        $projection = new SummaryProjection;
        $entries = array_fill(0, 128, ['role' => 'client', 'text' => 'x']);
        $this->assertCount(128, explode("\n", $projection->history($entries)));
        $prefix = 'Клиент: ';
        $text = str_repeat('x', 262144 - strlen($prefix));
        $this->assertSame($prefix.$text, $projection->history([['role' => 'client', 'text' => $text]]));
        $this->assertSame(262144, strlen($projection->request('', [['role' => 'client', 'text' => $text]], 'fake-model', 'v1', str_repeat('a', 64))->messages[1]['content']));

        try {
            $projection->history([['role' => 'client', 'text' => $text.'x']]);
            $this->fail('One byte beyond the bound must be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Summary input exceeds safe bounds.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }
}
