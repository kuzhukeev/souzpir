<?php

namespace Tests\Unit\Privacy\Research;

use App\Privacy\ContactBoundary;
use App\Privacy\InspectionOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContactBoundaryTypedTest extends TestCase
{
    public function test_clean_text_passes_both_paths_without_mutation(): void
    {
        $payload = $this->payload('Проверить наличие товара');
        $before = $payload;
        $boundary = new ContactBoundary;
        $boundary->assertSafe($payload);
        self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($payload));
        self::assertSame($before, $payload);
    }

    public function test_known_contact_patterns_and_false_positives_are_explicit_review_not_permissions(): void
    {
        $boundary = new ContactBoundary;
        foreach ([
            ['buyer@example.test', 'AI text contains an unresolved contact.'],
            ['Символ @ в учебном примере', 'AI text contains an unresolved contact.'],
            ['Позвоните +7 999 123 45 67', 'AI text contains an ambiguous phone-like number.'],
        ] as [$text, $message]) {
            $payload = $this->payload($text);
            self::assertSame(InspectionOutcome::ReviewRequired, $boundary->inspectTyped($payload));
            try {
                $boundary->assertSafe($payload);
                self::fail('Legacy boundary must reject this input.');
            } catch (InvalidArgumentException $error) {
                self::assertSame(InvalidArgumentException::class, $error::class);
                self::assertSame($message, $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString($text, (string) $error);
                self::assertStringNotContainsString($text, json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
            }
        }
    }

    public function test_malformed_and_unsupported_data_are_unavailable_and_legacy_exception_is_unchanged(): void
    {
        $boundary = new ContactBoundary;
        foreach ([
            [['messages' => []], 'Cannot inspect malformed AI messages.'],
            [$this->payload("\xff"), 'AI text cannot be safely inspected.'],
            [$this->payload(new \stdClass), 'AI data must contain only arrays and scalar values.'],
            [$this->payload(1_000_000_000), 'AI data contains an ambiguous long number.'],
        ] as [$payload, $message]) {
            self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($payload));
            try {
                $boundary->assertSafe($payload);
                self::fail('Legacy boundary must reject malformed input.');
            } catch (InvalidArgumentException $error) {
                self::assertSame(InvalidArgumentException::class, $error::class);
                self::assertSame($message, $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    public function test_later_malformed_input_dominates_earlier_contact_match_on_typed_path(): void
    {
        $payload = ['messages' => [
            ['role' => 'user', 'content' => 'buyer@example.test'],
            ['role' => 'user', 'content' => "\xff"],
        ]];
        $boundary = new ContactBoundary;
        self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($payload));
        try {
            $boundary->assertSafe($payload);
            self::fail('Legacy first error must remain unchanged.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('AI text contains an unresolved contact.', $error->getMessage());
        }
    }

    public function test_only_explicit_tool_arguments_require_valid_duplicate_free_json(): void
    {
        $boundary = new ContactBoundary;
        foreach (['{}', '[]', '{"nested":[{},[]]}'] as $json) {
            $payload = $this->toolPayload($json);
            $boundary->assertSafe($payload);
            self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($payload));
        }
        foreach (['{"city":', '{"a":1,"\\u0061":2}', '{"nested":{"a":1,"a":2}}', '"text"', 'null', '123'] as $json) {
            $payload = $this->toolPayload($json);
            // Preserve the old boundary: these strings were not rejected as
            // malformed merely for being explicit tool arguments.
            $boundary->assertSafe($payload);
            self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($payload));
        }
        foreach (['{"city":', '[ordinary unfinished text'] as $text) {
            $payload = $this->payload($text);
            $boundary->assertSafe($payload);
            self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($payload));
        }
    }

    public function test_malformed_tool_arguments_after_contact_dominate_typed_review_only(): void
    {
        $payload = $this->toolPayload('{"city":');
        array_unshift($payload['messages'], ['role' => 'user', 'content' => 'buyer@example.test']);
        $boundary = new ContactBoundary;
        self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($payload));
        try {
            $boundary->assertSafe($payload);
            self::fail('Legacy first contact error must remain unchanged.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('AI text contains an unresolved contact.', $error->getMessage());
        }
    }

    private function toolPayload(#[\SensitiveParameter] string $arguments): array
    {
        return ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'synthetic-call', 'function' => ['name' => 'synthetic', 'arguments' => $arguments],
        ]]]]];
    }

    private function payload(#[\SensitiveParameter] mixed $content): array
    {
        return ['messages' => [['role' => 'user', 'content' => $content]]];
    }
}
