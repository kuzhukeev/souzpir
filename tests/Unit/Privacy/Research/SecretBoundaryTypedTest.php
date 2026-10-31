<?php

namespace Tests\Unit\Privacy\Research;

use App\Privacy\InspectionOutcome;
use App\Privacy\Secrets\SecretBoundary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class SecretBoundaryTypedTest extends TestCase
{
    public function test_clear_input_retains_existing_semantics_and_is_not_changed(): void
    {
        $payload = ['messages' => [['role' => 'user', 'content' => 'Уточнить наличие товара']]];
        $original = $payload;
        $boundary = new SecretBoundary;
        $boundary->assertSafe($payload);
        self::assertSame(InspectionOutcome::Clear, $boundary->inspectTyped($payload));
        self::assertSame($original, $payload);
    }

    public function test_explicit_secret_like_matches_require_review_and_legacy_exception_is_unchanged(): void
    {
        foreach ([
            ['messages' => [['role' => 'user', 'content' => 'password: synthetic-marker']]],
            ['profile' => ['api_key' => 'synthetic-marker']],
            ['type' => 'password'],
            ['messages' => [['role' => 'user', 'content' => 'Basic dGVzdDp0ZXN0']]],
            ['messages' => [['role' => 'user', 'content' => 'Bearer synthetic-token-value']]],
        ] as $payload) {
            $before = $payload;
            $boundary = new SecretBoundary;
            self::assertSame(InspectionOutcome::ReviewRequired, $boundary->inspectTyped($payload));
            self::assertSame($before, $payload);
            $this->assertLegacyClosed($boundary, $payload);
        }
    }

    public function test_invalid_input_or_incomplete_inspection_are_unavailable(): void
    {
        $duplicate = ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'c', 'function' => ['name' => 'tool', 'arguments' => '{"a":1,"\u0061":2}'],
        ]]]]];
        foreach ([
            ['messages' => [['role' => 'user', 'content' => "\xff"]]],
            ['messages' => [['role' => 'user', 'content' => new \stdClass]]],
            ['messages' => [['role' => 'user', 'content' => NAN]]],
            $duplicate,
            // A prior match cannot mask a later invalid field or failed inspection.
            ['password' => 'synthetic-marker', 'messages' => [['role' => 'user', 'content' => "\xff"]]],
            ['messages' => [['role' => 'user', 'content' => "\xff"]], 'password' => 'synthetic-marker'],
        ] as $payload) {
            $boundary = new SecretBoundary;
            self::assertSame(InspectionOutcome::Unavailable, $boundary->inspectTyped($payload));
            $this->assertLegacyClosed($boundary, $payload);
        }
    }

    public function test_review_signal_is_not_a_guarantee_that_text_contains_a_secret(): void
    {
        $payload = ['messages' => [['role' => 'user', 'content' => 'В справочнике слово password: placeholder']]];
        self::assertSame(InspectionOutcome::ReviewRequired, (new SecretBoundary)->inspectTyped($payload));
    }

    public function test_regex_engine_failure_never_becomes_a_clean_typed_result(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            // This input reaches a legacy regex whose false return used to look
            // like a non-match; the typed path must treat it as unavailable.
            $payload = ['messages' => [['role' => 'user', 'content' => 'password']]];
            self::assertSame(InspectionOutcome::Unavailable, (new SecretBoundary)->inspectTyped($payload));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }

    private function assertLegacyClosed(SecretBoundary $boundary, #[SensitiveParameter] array $payload): void
    {
        try {
            $boundary->assertSafe($payload);
            self::fail('Legacy check must fail closed.');
        } catch (InvalidArgumentException $error) {
            self::assertSame(InvalidArgumentException::class, get_class($error));
            self::assertSame('AI data cannot be safely inspected for secrets.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-marker', (string) $error);
            self::assertStringNotContainsString('synthetic-marker', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }
}
