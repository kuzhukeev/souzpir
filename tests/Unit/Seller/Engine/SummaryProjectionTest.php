<?php

namespace Tests\Unit\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\SummaryPending;
use App\Seller\Engine\SummaryProjection;
use App\Seller\Engine\SummaryUnavailable;
use App\Seller\Engine\SummaryUncertain;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SummaryProjectionTest extends TestCase
{
    private function response(?string $content, ?array $error = null): AiResult
    {
        return new AiResult($content, [], [], 'fake-model', 0, 0, 'fake-call', $error);
    }

    public function test_formats_all_history_in_order_with_original_role_mapping(): void
    {
        $projection = new SummaryProjection;
        $this->assertSame("Клиент: Привет\nМенеджер: Ответ\nМенеджер: Служебное", $projection->history([
            ['role' => 'client', 'text' => 'Привет'], ['role' => 'agent', 'text' => 'Ответ'], ['role' => 'system', 'text' => 'Служебное'],
        ]));
        $this->assertSame('', $projection->history([]));
    }

    public function test_prototype_request_shape_and_synthetic_fake_gateway(): void
    {
        $projection = new SummaryProjection;
        $request = $projection->request('готовый шаблон', [['role' => 'client', 'text' => 'Привет']], 'fake-model', 'v1', str_repeat('a', 64));
        $this->assertSame([['role' => 'system', 'content' => 'готовый шаблон'], ['role' => 'user', 'content' => 'Клиент: Привет']], $request->messages);
        $this->assertSame(0.2, $request->temperature);
        $this->assertSame(300, $request->maxTokens);
        $this->assertSame('v1', $request->promptVersion);
        $this->assertSame(str_repeat('a', 64), $request->promptChecksum);
        $gateway = $this->createMock(AiGateway::class);
        $context = new AiContext('test-org', 'test-brand', 'summary', 'seller', 'conversation', 'test-conversation', 'test-key', ['conversation_content']);
        $gateway->expects($this->once())->method('complete')->with($this->identicalTo($request), $this->identicalTo($context))->willReturn($this->response(' синтетический итог '));
        $this->assertSame('синтетический итог', $projection->result($gateway->complete($request, $context), null));
        $this->assertInstanceOf(AiRequest::class, $request);
    }

    public function test_rejects_invalid_history_without_leaking_input(): void
    {
        try {
            (new SummaryProjection)->history([['role' => 'client', 'text' => ['private']]]);
            $this->fail('Invalid history must be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Invalid summary history.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_oversized_history_and_combined_prompt_fail_closed_without_truncation(): void
    {
        $projection = new SummaryProjection;
        $cases = [
            fn () => $projection->history(array_fill(0, 129, ['role' => 'client', 'text' => 'x'])),
            fn () => $projection->history([['role' => 'client', 'text' => str_repeat('x', 262145)]]),
            fn () => $projection->request(str_repeat('s', 262144), [['role' => 'client', 'text' => 'x']], 'fake-model', 'v1', str_repeat('a', 64)),
            fn () => $projection->request(str_repeat('s', 262145), [], 'fake-model', 'v1', str_repeat('a', 64)),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Oversized input must not be accepted or truncated.');
            } catch (InvalidArgumentException $error) {
                $this->assertContains($error->getMessage(), ['Invalid summary history.', 'Summary input exceeds safe bounds.']);
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_oversized_output_fails_without_exposing_it(): void
    {
        try {
            (new SummaryProjection)->result($this->response(str_repeat('x', 262145)), 'старый');
            $this->fail('Oversized output must not be accepted.');
        } catch (SummaryUnavailable $error) {
            $this->assertSame('Summary output is unavailable.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_empty_response_retains_previous_only_within_the_output_bound(): void
    {
        $projection = new SummaryProjection;
        $empty = $this->response(null, ['code' => 'empty_response']);
        $this->assertSame(str_repeat('x', 262144), $projection->result($empty, str_repeat('x', 262144)));
        try {
            $projection->result($empty, str_repeat('private', 37450));
            $this->fail('Oversized previous summary must not be returned or truncated.');
        } catch (SummaryUnavailable $error) {
            $this->assertSame('Summary output is unavailable.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_success_trims_content_and_known_empty_response_retains_previous(): void
    {
        $projection = new SummaryProjection;
        $this->assertSame('Итог', $projection->result($this->response(" \nИтог\t"), 'старый'));
        $this->assertSame('старый', $projection->result($this->response(null, ['code' => 'empty_response']), 'старый'));
        $this->assertNull($projection->result($this->response(null, ['code' => 'empty_response']), null));
    }

    public function test_unusable_successes_never_become_a_completed_summary(): void
    {
        foreach ([
            $this->response(null),
            $this->response(" \n\t"),
            new AiResult('не итог', [['id' => 'tool']], [], 'fake-model', 0, 0, 'fake-call'),
            new AiResult(null, [['id' => 'tool']], [], 'fake-model', 0, 0, 'fake-call', ['code' => 'empty_response']),
            $this->response('не пусто', ['code' => 'empty_response']),
        ] as $result) {
            try {
                (new SummaryProjection)->result($result, 'старый');
                $this->fail('Unusable result must not become a summary.');
            } catch (SummaryUnavailable $error) {
                $this->assertSame('Summary output is unavailable.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_pending_is_not_a_completed_summary(): void
    {
        $this->expectException(SummaryPending::class);
        (new SummaryProjection)->result($this->response(null, ['code' => 'in_progress']), 'старый');
    }

    public function test_unknown_or_uncertain_error_does_not_become_previous_summary(): void
    {
        try {
            (new SummaryProjection)->result($this->response(null, ['code' => 'private-provider-error']), 'секретный итог');
            $this->fail('Uncertain result must not be treated as success.');
        } catch (SummaryUncertain $error) {
            $this->assertSame('fake-call', $error->aiCallId);
            $this->assertSame('Summary call has an uncertain outcome.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_unsettled_cost_never_confirms_text_or_empty_fallback(): void
    {
        foreach ([
            new AiResult('private-synthetic-summary', [], [], 'fake-model', 0, null, 'private-synthetic-call'),
            new AiResult(null, [], [], 'fake-model', 0, null, 'private-synthetic-call', ['code' => 'empty_response']),
        ] as $result) {
            try {
                (new SummaryProjection)->result($result, 'private-synthetic-previous');
                self::fail('An unsettled result must not become a summary.');
            } catch (SummaryUncertain $error) {
                self::assertSame('private-synthetic-call', $error->aiCallId);
                self::assertSame('Summary call has an uncertain outcome.', $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('private-synthetic-', (string) $error);
            }
        }
    }

    public function test_gateway_pending_with_unknown_cost_remains_pending_and_hides_call_in_trace(): void
    {
        $result = new AiResult(null, [], [], 'fake-model', 0, null, 'private-synthetic-call', ['code' => 'in_progress']);
        try {
            (new SummaryProjection)->result($result, 'private-synthetic-previous');
            self::fail('Pending is not a completed summary.');
        } catch (SummaryPending $error) {
            self::assertSame('private-synthetic-call', $error->aiCallId);
            self::assertSame('Summary call is still in progress.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private-synthetic-', (string) $error);
        }
    }

    public function test_negative_cost_never_confirms_summary(): void
    {
        $result = new AiResult('synthetic summary', [], [], 'fake-model', 0, -1, 'fake-call');
        $this->expectException(SummaryUnavailable::class);
        (new SummaryProjection)->result($result, 'previous');
    }

    public function test_invalid_utf8_never_becomes_request_or_summary(): void
    {
        $invalid = "private-synthetic-value\xff";
        $projection = new SummaryProjection;
        foreach ([
            fn () => $projection->request($invalid, [], 'fake-model', 'v1', str_repeat('a', 64)),
            fn () => $projection->request('prompt', [], $invalid, 'v1', str_repeat('a', 64)),
            fn () => $projection->request('prompt', [], 'fake-model', $invalid, str_repeat('a', 64)),
            fn () => $projection->history([['role' => 'client', 'text' => $invalid]]),
            fn () => $projection->history([['role' => $invalid, 'text' => 'text']]),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid UTF-8 must not become a request.');
            } catch (InvalidArgumentException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('private-synthetic-value', (string) $error);
            }
        }
        foreach ([
            [$this->response($invalid), null],
            [$this->response(null, ['code' => 'empty_response']), $invalid],
        ] as [$result, $previous]) {
            try {
                $projection->result($result, $previous);
                self::fail('Invalid UTF-8 must not become a summary.');
            } catch (SummaryUnavailable $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('private-synthetic-value', (string) $error);
            }
        }
    }
}
