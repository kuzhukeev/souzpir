<?php

namespace Tests\Unit\Privacy\Research;

use App\Privacy\ContactBoundary;
use App\Privacy\InspectionOutcome;
use App\Privacy\Secrets\SecretBoundary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/** Typed privacy detects malformed tool structure without acting as a full schema validator. */
final class MalformedToolStructureTest extends TestCase
{
    public function test_bad_function_and_tool_call_shapes_are_unavailable_for_both_boundaries(): void
    {
        foreach ([
            null, true, 123, 'text', [],
            ['id' => 'synthetic'],
            ['function' => null], ['function' => true], ['function' => 123], ['function' => 1.5],
            ['function' => 'synthetic'], ['function' => []], ['function' => ['synthetic']],
            ['function' => ['name' => 'synthetic']],
        ] as $tool) {
            $this->assertBothUnavailable($this->request($tool));
        }
    }

    public function test_missing_or_nonstructured_arguments_and_invalid_json_are_unavailable(): void
    {
        foreach ([null, true, false, 123, 1.5, '{"city":', '{"a":1,"\\u0061":2}', 'null', '123', '"text"'] as $arguments) {
            $this->assertBothUnavailable($this->request(['id' => 'synthetic', 'function' => ['name' => 'tool', 'arguments' => $arguments]]));
        }
    }

    public function test_explicit_malformed_tool_calls_container_is_unavailable(): void
    {
        foreach ([null, true, 123, 1.5, 'text', ['function' => ['arguments' => '{}']]] as $calls) {
            $payload = ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => $calls]]];
            $this->assertBothUnavailable($payload);
        }
    }

    public function test_structured_arguments_and_no_tool_calls_remain_accepted(): void
    {
        $plain = ['messages' => [['role' => 'user', 'content' => 'Проверить наличие']]];
        foreach ([new ContactBoundary, new SecretBoundary] as $boundary) {
            self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($plain));
            $boundary->assertSafe($plain);
            $emptyCalls = ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => []]]];
            self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($emptyCalls));
            $boundary->assertSafe($emptyCalls);
            foreach (['{}', '[]', '{"nested":[{},[]]}', [], ['city' => 'Тула']] as $arguments) {
                $request = $this->request(['id' => 'synthetic', 'function' => ['name' => 'tool', 'arguments' => $arguments]]);
                self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($request));
                $boundary->assertSafe($request);
            }
        }
    }

    public function test_secret_boundary_still_scans_nonmessage_payload_without_becoming_a_full_schema_validator(): void
    {
        $nonMessagePayload = ['synthetic_field' => 'ordinary value'];
        $secret = new SecretBoundary;
        $secret->assertSafe($nonMessagePayload);
        self::assertSame(InspectionOutcome::Clear, $secret->inspectTyped($nonMessagePayload));
        self::assertSame(InspectionOutcome::Unavailable, (new ContactBoundary)->inspectTyped($nonMessagePayload));
    }

    public function test_late_malformed_tool_structure_takes_priority_over_explicit_review(): void
    {
        foreach ([
            [new ContactBoundary, 'Синтетический символ @'],
            [new SecretBoundary, 'password: synthetic-marker'],
        ] as [$boundary, $reviewText]) {
            $valid = $this->request(['id' => 'synthetic', 'function' => ['name' => 'tool', 'arguments' => '{}']]);
            array_unshift($valid['messages'], ['role' => 'user', 'content' => $reviewText]);
            self::assertSame(InspectionOutcome::ReviewRequired, $boundary->inspectTyped($valid));
            $valid['messages'][1]['tool_calls'][0]['function'] = null;
            self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($valid));
        }
    }

    public function test_legacy_behavior_for_independently_verified_baseline_examples_is_unchanged(): void
    {
        // These outcomes were observed on committed baseline 9c3871b, not inferred from typed statuses.
        $withoutFunction = $this->request(['id' => 'synthetic', 'function' => null]);
        (new ContactBoundary)->assertSafe($withoutFunction);
        (new SecretBoundary)->assertSafe($withoutFunction);
        self::assertTrue(true);

        foreach ([true, '{"city":', '{"a":1,"\\u0061":2}'] as $arguments) {
            $request = $this->request(['id' => 'synthetic', 'function' => ['name' => 'tool', 'arguments' => $arguments]]);
            (new ContactBoundary)->assertSafe($request);
            try {
                (new SecretBoundary)->assertSafe($request);
                self::fail('Legacy secret boundary must retain its existing rejection.');
            } catch (InvalidArgumentException $error) {
                self::assertSame(InvalidArgumentException::class, $error::class);
                self::assertSame('AI data cannot be safely inspected for secrets.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    private function assertBothUnavailable(#[SensitiveParameter] array $payload): void
    {
        $before = $payload;
        foreach ([new ContactBoundary, new SecretBoundary] as $boundary) {
            self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($payload));
            self::assertSame($before, $payload);
        }
    }

    private function request(#[SensitiveParameter] mixed $tool): array
    {
        return ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [$tool]]]];
    }
}
