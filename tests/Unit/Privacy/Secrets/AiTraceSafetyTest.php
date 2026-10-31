<?php

namespace Tests\Unit\Privacy\Secrets;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\OpenRouterDriver;
use App\Ai\Drivers\Uncertain;
use App\Ai\Gateway;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiResult;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class AiTraceSafetyTest extends TestCase
{
    private function assertSafeError(#[\SensitiveParameter] callable $callback, #[\SensitiveParameter] string $marker, string $expectedClass): void
    {
        try {
            $callback();
            self::fail('Expected a safe error.');
        } catch (Throwable $error) {
            self::assertInstanceOf($expectedClass, $error);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($marker, (string) $error);
            self::assertStringNotContainsString($marker, json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_canonical_json_error_never_contains_raw_payload(): void
    {
        $marker = 'SYNTHETIC_MARKER_JSON';
        $this->assertSafeError(fn () => CanonicalJson::hash(['content' => $marker, 'bad' => "\xff"]), $marker, \InvalidArgumentException::class);
    }

    public function test_invalid_context_hides_constructor_arguments(): void
    {
        $marker = 'SYNTHETIC_CONTEXT_MARKER';
        $this->assertSafeError(fn () => new AiContext('org', 'brand', $marker, 'invalid', 'brand', 'object', 'key', ['contact']), $marker, \InvalidArgumentException::class);
    }

    public function test_invalid_result_hides_result_arguments(): void
    {
        $marker = 'SYNTHETIC_RESULT_MARKER';
        $this->assertSafeError(fn () => AiResult::fromArray(['content' => $marker, 'toolCalls' => [], 'usage' => null, 'model' => 'm', 'latencyMs' => 1, 'costMicros' => 0, 'aiCallId' => 'x', 'error' => null]), $marker, \InvalidArgumentException::class);
    }

    public function test_driver_transport_error_does_not_chain_sensitive_details(): void
    {
        $marker = 'SYNTHETIC_TRANSPORT_MARKER';
        config(['features.E0-05.defaults.openrouter_key' => 'synthetic-key']);
        Http::shouldReceive('withToken')->once()->andThrow(new \RuntimeException($marker));
        $this->assertSafeError(fn () => app(OpenRouterDriver::class)->complete(['messages' => [], 'model' => 'm']), $marker, Uncertain::class);
    }

    public function test_driver_wire_body_error_does_not_leak_schema(): void
    {
        $marker = 'SYNTHETIC_SCHEMA_MARKER';
        $body = ['model' => 'anthropic/test', 'messages' => [], 'response_format' => ['type' => 'json_schema', 'json_schema' => ['schema' => ['content' => $marker, 'bad' => "\xff"]]]];
        $this->assertSafeError(fn () => app(OpenRouterDriver::class)->wireBody($body), $marker, \InvalidArgumentException::class);
    }

    public function test_reconciliation_does_not_leak_database_exception_or_result(): void
    {
        $marker = 'SYNTHETIC_DB_SECRET';
        $tenant = new TenantContext((string) Str::ulid(), (string) Str::ulid());
        $this->app->instance(TenantContext::class, $tenant);
        config(['features.E0-05.defaults.budget_threshold_basis_points' => null]);
        DB::shouldReceive('table')->twice()->andThrow(new \RuntimeException($marker));
        $result = new AiResult($marker, [], ['promptTokens' => 1, 'completionTokens' => 1], 'm', 1, 1, 'call');
        $this->assertSafeError(fn () => app(Gateway::class)->reconcileUnbilled($tenant, 'call', 'evidence'), $marker, \RuntimeException::class);
        $this->assertSafeError(fn () => app(Gateway::class)->reconcileSuccess($tenant, $result, 'evidence'), $marker, \RuntimeException::class);
    }

    public function test_reconciliation_error_hides_evidence(): void
    {
        $marker = 'SYNTHETIC_EVIDENCE_MARKER';
        $this->assertSafeError(fn () => app(Gateway::class)->reconcileUnbilled(new TenantContext, 'synthetic-call', $marker), $marker, \InvalidArgumentException::class);
    }
}
