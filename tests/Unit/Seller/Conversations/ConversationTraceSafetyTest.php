<?php

namespace Tests\Unit\Seller\Conversations;

use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\EloquentConversationRepository;
use App\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;
use Throwable;

/** Standalone tests: no application bootstrap or database access. */
final class ConversationTraceSafetyTest extends TestCase
{
    public function test_claim_and_commit_trace_args_hide_synthetic_values(): void
    {
        $this->requireTraceArguments();
        $marker = 'synthetic-message-claim';
        $command = new TurnCommand('invalid', 'invalid', $marker, null, 'request');
        $trace = $this->trace(fn () => (new EloquentConversationRepository)->claimTurn(new TenantContext, $command));
        self::assertStringNotContainsString($marker, $trace);

        $token = str_repeat('a', 64);
        $output = 'synthetic-message-outcome';
        $claim = new TurnClaim('claimed', 'turn', 'conversation', 'organization', 'brand', 0, $token);
        $outcome = new TurnOutcome('wrong-turn', 'conversation', 'completed', 1, null, null, null, [], ['message' => $output]);
        $trace = $this->trace(fn () => (new EloquentConversationRepository)->commitTurn($claim, $outcome));
        self::assertStringNotContainsString($token, $trace);
        self::assertStringNotContainsString($output, $trace);
    }

    public function test_nested_input_validation_hides_record_and_ingest_arguments(): void
    {
        $this->requireTraceArguments();
        $markers = ['synthetic-channel', 'synthetic-external', 'synthetic-payload', 'synthetic-conversation', 'synthetic-text'];
        $recorder = new InboundRecorder;
        $trace = $this->trace(fn () => $recorder->record(new TenantContext, $markers[0], $markers[1], ['v' => $markers[2]]));
        foreach (array_slice($markers, 0, 3) as $marker) {
            self::assertStringNotContainsString($marker, $trace);
        }
        $trace = $this->trace(fn () => $recorder->ingest(new TenantContext, $markers[0], $markers[1], ['v' => $markers[2]], $markers[3], $markers[4]));
        foreach ($markers as $marker) {
            self::assertStringNotContainsString($marker, $trace);
        }
    }

    public function test_sensitive_annotations_cover_interface_and_private_helpers(): void
    {
        foreach ([
            [ConversationRepository::class, 'claimTurn'], [ConversationRepository::class, 'commitTurn'],
            [EloquentConversationRepository::class, 'claimTurn'], [EloquentConversationRepository::class, 'commitTurn'],
            [InboundRecorder::class, 'record'], [InboundRecorder::class, 'ingest'],
            [InboundRecorder::class, 'validateInput'], [InboundRecorder::class, 'recordLocked'],
            [CanonicalJson::class, 'hash'], [CanonicalJson::class, 'normalize'],
        ] as [$class, $method]) {
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class), $class.'::'.$method.'($'.$parameter->getName().')');
            }
        }
    }

    public function test_invalid_json_keeps_canonical_hash_argument_redacted_in_its_own_frame(): void
    {
        $this->requireTraceArguments();
        try {
            CanonicalJson::hash(['nested' => ['text' => 'synthetic-json-marker', 'bad' => "\xff"]]);
            self::fail('Invalid UTF-8 was accepted.');
        } catch (\JsonException $error) {
            $frame = array_values(array_filter($error->getTrace(), static fn (array $item): bool => ($item['class'] ?? null) === CanonicalJson::class && ($item['function'] ?? null) === 'hash'
            ))[0] ?? null;
            self::assertNotNull($frame);
            self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0]);
            // PHP's own json_encode frame is not redacted: this is not a full trace-safety claim.
        }
    }

    private function requireTraceArguments(): void
    {
        self::assertSame('0', (string) ini_get('zend.exception_ignore_args'), 'Run with -d zend.exception_ignore_args=0.');
    }

    private function trace(callable $callback): string
    {
        try {
            $callback();
        } catch (Throwable $error) {
            return var_export($error->getTrace(), true);
        }

        self::fail('An exception was expected.');
    }
}
