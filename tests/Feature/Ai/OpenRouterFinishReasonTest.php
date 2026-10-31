<?php

namespace Tests\Feature\Ai;

use App\Ai\Gateway;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/** HTTP is always faked; completed accounting here does not prove provider finality. */
final class OpenRouterFinishReasonTest extends TestCase
{
    use DatabaseMigrations;

    private function scope(): array
    {
        $org = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Finish reason fixture', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $org, 'name' => 'Synthetic', 'status' => 'active', 'theme' => 'neutral', 'settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        DB::table('ai_budgets')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'family' => 'seller', 'period' => now('UTC')->startOfMonth()->toDateString(), 'limit_micros' => 1000, 'spent_micros' => 0, 'reserved_micros' => 0, 'unresolved_count' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$org, $brand];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only-fake-key', 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100], 'features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 4096, 'max_output_tokens' => 1000]]]);
        Http::preventStrayRequests();
    }

    private function invoke(array $scope, string $key = 'same', int $attempt = 1, ?string $parent = null): AiResult
    {
        $request = new AiRequest([['role' => 'user', 'content' => 'synthetic fixture']], 'google/gemini-2.5-flash', 0.5);
        $context = new AiContext($scope[0], $scope[1], 'responder', 'seller', 'brand', $scope[1], $key, ['knowledge_public'], $attempt, $parent);

        return app(TenantContextRunner::class)->run(new TenantContext($scope[0], $scope[1], source: 'job'), fn () => app(AiGateway::class)->complete($request, $context));
    }

    private function fakeResponse(array $scope, array $choice, int|float $cost, int &$posts, ?callable $onPost = null): void
    {
        // Each matrix case gets a fresh fake factory: Factory::fake appends callbacks.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $body = json_encode(['id' => 'gen-'.strtolower($scope[0]), 'model' => 'google/gemini-2.5-flash', 'choices' => [$choice], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'cost' => $cost]], JSON_THROW_ON_ERROR);
        Http::fake(['openrouter.ai/*' => function ($request, array $options) use (&$posts, $body, $onPost) {
            self::assertSame('POST', $request->method());
            self::assertFalse($options['allow_redirects'] ?? true);
            $posts++;
            if ($onPost !== null) {
                $onPost();
            }

            return Http::response($body, 200);
        }]);
    }

    public function test_incomplete_unknown_and_contradictory_finish_reasons_preserve_reservation(): void
    {
        $tool = ['id' => 'call-1', 'function' => ['name' => 'lookup', 'arguments' => '{"query":"synthetic"}']];
        $cases = [
            'length' => ['finish_reason' => 'length', 'message' => ['content' => 'partial']],
            'missing' => ['message' => ['content' => 'partial']],
            'null' => ['finish_reason' => null, 'message' => ['content' => 'partial']],
            'unknown' => ['finish_reason' => 'future-reason', 'message' => ['content' => 'partial']],
            'invalid_type' => ['finish_reason' => ['stop'], 'message' => ['content' => 'partial']],
            'content_filter' => ['finish_reason' => 'content_filter', 'message' => ['content' => 'partial']],
            'stop_with_null_content' => ['finish_reason' => 'stop', 'message' => ['content' => null]],
            'stop_with_tool_calls' => ['finish_reason' => 'stop', 'message' => ['content' => null, 'tool_calls' => [$tool]]],
            'tool_finish_without_tools' => ['finish_reason' => 'tool_calls', 'message' => ['content' => 'partial', 'tool_calls' => []]],
        ];
        foreach ($cases as $name => $choice) {
            foreach ([0, 0.00015] as $cost) {
                $scope = $this->scope();
                $posts = 0;
                $this->fakeResponse($scope, $choice, $cost, $posts);
                $first = $this->invoke($scope);
                self::assertSame('provider_uncertain', $first->error['code'], $name);
                self::assertNull($first->content, $name);
                self::assertNull($first->costMicros, $name);
                $replay = $this->invoke($scope);
                self::assertSame($first->aiCallId, $replay->aiCallId, $name);
                self::assertSame('provider_uncertain', $replay->error['code'], $name);
                try {
                    $this->invoke($scope, 'retry', 2, $first->aiCallId);
                    self::fail('Unexpected retry of an uncertain call: '.$name);
                } catch (LogicException) {
                }
                $call = DB::table('ai_calls')->where('id', $first->aiCallId)->first();
                $budget = DB::table('ai_budgets')->where('organization_id', $scope[0])->first();
                self::assertSame('uncertain', $call->status, $name);
                self::assertNull($call->cost_micros, $name);
                self::assertNull($call->result_encrypted, $name);
                if (Schema::hasColumn('ai_calls', 'provider_generation_id')) {
                    self::assertSame('gen-'.strtolower($scope[0]), $call->provider_generation_id, $name);
                }
                self::assertSame(100, (int) $budget->reserved_micros, $name);
                self::assertSame(0, (int) $budget->spent_micros, $name);
                self::assertSame(1, (int) $budget->unresolved_count, $name);
                self::assertSame(0, DB::table('object_costs')->where('organization_id', $scope[0])->count(), $name);
                self::assertSame(1, DB::table('ai_calls')->where('organization_id', $scope[0])->count(), $name);
                self::assertSame(1, $posts, $name);
            }
        }
    }

    public function test_complete_stop_and_tool_only_results_follow_existing_accounting_path(): void
    {
        $tool = ['id' => 'call-1', 'function' => ['name' => 'lookup', 'arguments' => '{"query":"synthetic"}']];
        $cases = [
            [['finish_reason' => 'stop', 'message' => ['content' => 'complete answer']], 0, 'complete answer', []],
            [['finish_reason' => 'stop', 'message' => ['content' => 'complete answer']], 0.00015, 'complete answer', []],
            [['finish_reason' => 'tool_calls', 'message' => ['content' => null, 'tool_calls' => [$tool]]], 0, null, [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['query' => 'synthetic']]]],
            [['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [$tool]]], 0.00015, null, [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['query' => 'synthetic']]]],
        ];
        foreach ($cases as [$choice, $cost, $content, $tools]) {
            $scope = $this->scope();
            $posts = 0;
            $this->fakeResponse($scope, $choice, $cost, $posts);
            $result = $this->invoke($scope);
            $expected = $cost === 0 ? 0 : 150;
            self::assertNull($result->error);
            self::assertSame($content, $result->content);
            self::assertSame($tools, $result->toolCalls);
            self::assertSame($expected, $result->costMicros);
            $replay = $this->invoke($scope);
            self::assertSame($result->aiCallId, $replay->aiCallId);
            self::assertSame($result->toArray(), $replay->toArray());
            $call = DB::table('ai_calls')->where('id', $result->aiCallId)->first();
            $budget = DB::table('ai_budgets')->where('organization_id', $scope[0])->first();
            self::assertSame('completed', $call->status);
            self::assertSame($expected, (int) $call->cost_micros);
            self::assertNotNull($call->result_encrypted);
            $persisted = json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($content, $persisted['content']);
            self::assertSame($tools, $persisted['toolCalls']);
            self::assertSame($expected, $persisted['costMicros']);
            if (Schema::hasColumn('ai_calls', 'provider_generation_id')) {
                self::assertSame('gen-'.strtolower($scope[0]), $call->provider_generation_id);
            }
            self::assertSame(0, (int) $budget->reserved_micros);
            self::assertSame($expected, (int) $budget->spent_micros);
            self::assertSame(0, (int) $budget->unresolved_count);
            self::assertSame($expected, (int) DB::table('object_costs')->where('organization_id', $scope[0])->value('cost_micros'));
            self::assertSame(1, $posts);
        }
    }

    public function test_already_persisted_evidence_is_not_erased_when_length_response_is_rejected(): void
    {
        $scope = $this->scope();
        $posts = 0;
        $ciphertext = Crypt::encryptString('synthetic pre-existing encrypted evidence');
        $withProviderId = Schema::hasColumn('ai_calls', 'provider_generation_id');
        // Synthetic fault injection: normal main Gateway does not persist cost/ID before parsing.
        $insertEvidence = function () use ($scope, $ciphertext, $withProviderId): void {
            $fields = ['cost_micros' => 29, 'result_encrypted' => $ciphertext];
            if ($withProviderId) {
                $fields['provider_generation_id'] = 'gen-existing-synthetic';
            }
            DB::table('ai_calls')->where('organization_id', $scope[0])->where('status', 'reserved')->update($fields);
        };
        // No generation ID in this synthetic response: existing IDs must never be rebound.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/*' => function ($request, array $options) use (&$posts, $insertEvidence) {
            self::assertSame('POST', $request->method());
            self::assertFalse($options['allow_redirects'] ?? true);
            $posts++;
            $insertEvidence();

            return Http::response('{"choices":[{"finish_reason":"length","message":{"content":"partial"}}],"usage":{"prompt_tokens":2,"completion_tokens":3,"cost":0.00015}}', 200);
        }]);
        $result = $this->invoke($scope);
        self::assertSame('provider_uncertain', $result->error['code']);
        $call = DB::table('ai_calls')->where('id', $result->aiCallId)->first();
        $budget = DB::table('ai_budgets')->where('organization_id', $scope[0])->first();
        self::assertSame('uncertain', $call->status);
        self::assertSame(29, (int) $call->cost_micros);
        self::assertSame($ciphertext, $call->result_encrypted);
        if ($withProviderId) {
            self::assertSame('gen-existing-synthetic', $call->provider_generation_id);
        }
        self::assertSame(100, (int) $budget->reserved_micros);
        self::assertSame(0, (int) $budget->spent_micros);
        self::assertSame(1, (int) $budget->unresolved_count);
        self::assertSame($result->aiCallId, $this->invoke($scope)->aiCallId);
        self::assertSame(1, $posts);
    }
}
