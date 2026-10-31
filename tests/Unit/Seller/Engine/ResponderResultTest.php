<?php

namespace Tests\Unit\Seller\Engine;

use App\Contracts\Ai\AiResult;
use App\Seller\Engine\ResponderResult;
use PHPUnit\Framework\TestCase;

final class ResponderResultTest extends TestCase
{
    private function fixtureResult(?string $text, array $tools = [], ?int $cost = 0, ?array $error = null): AiResult
    {
        return new AiResult($text, $tools, [], 'synthetic-model', 1, $cost, 'synthetic-call', $error);
    }

    public function test_settled_text_is_cleaned_like_the_prototype_but_remains_only_a_guard_candidate(): void
    {
        $interpreter = new ResponderResult;
        foreach ([
            '  «Ответ: Здравствуйте!»  ' => 'Здравствуйте!',
            '"Антон: Добрый день"' => 'Добрый день',
            'Менеджер:  Уточню условия.' => 'Уточню условия.',
            ' ответ: текст ' => 'ответ: текст',
            'Привет! «Текст»' => 'Привет! «Текст',
        ] as $input => $expected) {
            self::assertSame(['status' => 'candidate', 'text' => $expected], $interpreter->interpret($this->fixtureResult($input)));
        }
    }

    public function test_pending_and_unknown_or_failed_results_never_return_a_candidate_or_tool_signal(): void
    {
        $interpreter = new ResponderResult;
        $private = 'synthetic-private-content';
        $tool = [['id' => 'synthetic-id', 'name' => 'synthetic', 'arguments' => ['private' => $private]]];
        foreach ([
            [$this->fixtureResult($private, $tool, null, ['code' => 'in_progress']), 'pending'],
            [$this->fixtureResult($private, $tool, 0, ['code' => 'in_progress']), 'pending'],
            [$this->fixtureResult($private, $tool, null), 'uncertain'],
            [$this->fixtureResult($private, $tool, null, ['code' => 'empty_response']), 'uncertain'],
            [$this->fixtureResult($private, $tool, 0, ['code' => 'provider_uncertain', 'detail' => $private]), 'uncertain'],
            [$this->fixtureResult($private, $tool, -1), 'unavailable'],
            [$this->fixtureResult($private, $tool, 0, ['code' => 'empty_response']), 'unavailable'],
        ] as [$source, $status]) {
            $outcome = $interpreter->interpret($source);
            self::assertSame(['status' => $status, 'text' => null], $outcome);
            self::assertStringNotContainsString($private, json_encode($outcome, JSON_THROW_ON_ERROR));
        }
    }

    public function test_tool_calls_take_precedence_over_text_without_exposing_arguments_or_authorizing_execution(): void
    {
        $tool = [['id' => 'synthetic', 'name' => 'some-tool', 'arguments' => ['secret' => 'synthetic-private-argument']]];
        $outcome = (new ResponderResult)->interpret($this->fixtureResult('synthetic-private-text', $tool));
        self::assertSame(['status' => 'tool_calls', 'text' => null], $outcome);
        self::assertStringNotContainsString('synthetic-private', json_encode($outcome, JSON_THROW_ON_ERROR));
    }

    public function test_empty_invalid_oversized_or_malformed_results_are_unavailable(): void
    {
        $interpreter = new ResponderResult;
        foreach ([
            $this->fixtureResult(null), $this->fixtureResult('  '), $this->fixtureResult('«Ответ: »'),
            $this->fixtureResult("\xff PRIVATE"), $this->fixtureResult(str_repeat('x', 262145)),
            $this->fixtureResult('text', ['not-a-list' => ['private']]),
        ] as $source) {
            self::assertSame(['status' => 'unavailable', 'text' => null], $interpreter->interpret($source));
        }
    }

    public function test_text_limit_and_failed_pattern_evaluation_do_not_produce_an_invalid_candidate(): void
    {
        $interpreter = new ResponderResult;
        $bounded = str_repeat('x', 262144);
        self::assertSame(['status' => 'candidate', 'text' => $bounded], $interpreter->interpret($this->fixtureResult($bounded)));

        $previousLimit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '1');
            self::assertSame(['status' => 'unavailable', 'text' => null], $interpreter->interpret($this->fixtureResult('Ответ:  текст')));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }
    }
}
