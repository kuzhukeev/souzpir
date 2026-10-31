<?php

namespace Tests\Feature\Ai;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\Rejected;
use App\Ai\Gateway;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class OpenRouterFailClosedTest extends TestCase
{
    use DatabaseMigrations;

    private function scope(): array
    {
        $org = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Fake provider test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $org, 'name' => 'Brand', 'status' => 'active', 'theme' => 'neutral', 'settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        DB::table('ai_budgets')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'family' => 'seller', 'period' => now('UTC')->startOfMonth()->toDateString(), 'limit_micros' => 1000, 'spent_micros' => 0, 'reserved_micros' => 0, 'unresolved_count' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$org, $brand];
    }

    private function request(): AiRequest
    {
        return new AiRequest([['role' => 'user', 'content' => 'synthetic request']], 'google/gemini-2.5-flash', 0.5);
    }

    private function context(array $scope, string $key, int $attempt = 1, ?string $parent = null): AiContext
    {
        return new AiContext($scope[0], $scope[1], 'responder', 'seller', 'brand', $scope[1], $key, ['knowledge_public'], $attempt, $parent);
    }

    private function within(array $scope, \Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($scope[0], $scope[1], source: 'job'), $action);
    }

    public function test_ambiguous_provider_outcomes_never_repeat_or_release_the_reserved_amount(): void
    {
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'fake-key', 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100], 'features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 4096, 'max_output_tokens' => 1000]]]);
        Http::preventStrayRequests();
        $cases = [
            '400' => [400, 'not necessarily unbilled'], '401' => [401, 'x'], '402' => [402, 'x'],
            '403' => [403, 'x'], '404' => [404, 'not found'], '422' => [422, 'x'],
            '429' => [429, 'rate limited'], '500' => [500, 'x'], '503' => [503, 'x'],
            '307' => [307, 'redirect'], '308' => [308, 'redirect'],
            '202' => [202, '{"choices":[{"message":{"content":"hello"}}],"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":0}}'],
            '206' => [206, '{"choices":[{"message":{"content":"hello"}}],"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":0}}'],
            'empty_message_object' => [200, '{"choices":[{"message":{}}],"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":0}}'],
            'invalid_json' => [200, 'not json'], 'truncated_json' => [200, '{"choices":[{"message":'],
            'invalid_usage' => [200, '{"choices":[{"message":{"content":"hello"}}],"usage":{"prompt_tokens":null,"completion_tokens":1}}'],
            'invalid_tool_arguments' => [200, '{"choices":[{"message":{"content":null,"tool_calls":[{"id":"1","function":{"name":"tool","arguments":"{"}}]}}],"usage":{"prompt_tokens":1,"completion_tokens":1}}'],
            'unknown_cost' => [200, '{"choices":[{"message":{"content":"hello"}}],"usage":{"prompt_tokens":1,"completion_tokens":1}}'],
            'timeout' => [0, 'timeout'], 'disconnect' => [0, 'connection reset'],
        ];
        foreach ($cases as $label => [$status, $body]) {
            $scope = $this->scope();
            $attempts = 0;
            // Factory::fake appends callbacks; each case needs a fresh factory.
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['openrouter.ai/*' => function ($request, array $options) use (&$attempts, $status, $body) {
                self::assertSame('POST', $request->method());
                self::assertFalse($options['allow_redirects'] ?? true, 'Redirect following must be disabled in the actual HTTP options.');
                $attempts++;
                if ($status === 0) {
                    throw new ConnectionException('synthetic '.$body);
                }

                return Http::response($body, $status, in_array($status, [307, 308], true) ? ['Location' => 'https://redirect-target.invalid/'] : []);
            }]);
            $this->within($scope, function () use ($scope, $label, &$attempts): void {
                $first = app(AiGateway::class)->complete($this->request(), $this->context($scope, 'same'));
                self::assertSame($label === 'unknown_cost' ? 'cost_unknown' : 'provider_uncertain', $first->error['code'], $label);
                $second = app(AiGateway::class)->complete($this->request(), $this->context($scope, 'same'));
                self::assertSame($first->aiCallId, $second->aiCallId, $label);
                self::assertSame($first->error['code'], $second->error['code'], $label);
                foreach ([
                    fn () => app(AiGateway::class)->complete($this->request(), $this->context($scope, 'child', 2, $first->aiCallId)),
                    fn () => app(AiGateway::class)->complete($this->request(), $this->context($scope, 'different-key')),
                    fn () => app(Gateway::class)->reconcileUnbilled(app(TenantContext::class), $first->aiCallId, 'unverified operator text'),
                ] as $action) {
                    try {
                        $action();
                        self::fail('Unsafe retry or reconciliation accepted: '.$label);
                    } catch (LogicException) {
                    }
                }
                $call = DB::table('ai_calls')->where('id', $first->aiCallId)->first();
                $budget = DB::table('ai_budgets')->where('organization_id', $scope[0])->first();
                self::assertSame('uncertain', $call->status, $label);
                self::assertNull($call->cost_micros, $label);
                self::assertSame(100, (int) $budget->reserved_micros, $label);
                self::assertSame(1, (int) $budget->unresolved_count, $label);
                self::assertSame(0, (int) $budget->spent_micros, $label);
                self::assertSame(0, DB::table('object_costs')->where('organization_id', $scope[0])->count(), $label);
                self::assertSame(1, DB::table('ai_calls')->where('organization_id', $scope[0])->count(), $label);
                self::assertSame(1, $attempts, $label);
            });
        }
    }

    public function test_local_preflight_failure_never_sends_http_and_fake_driver_stays_usable(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $scope = $this->scope();
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => '', 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100], 'features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 4096, 'max_output_tokens' => 1000]]]);
        $this->within($scope, function () use ($scope): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context($scope, 'no-key'));
                self::fail('Missing key accepted.');
            } catch (Rejected) {
            }
            self::assertSame('failed', DB::table('ai_calls')->where('organization_id', $scope[0])->value('status'));
            self::assertSame(0, (int) DB::table('ai_budgets')->where('organization_id', $scope[0])->value('reserved_micros'));
            try {
                $unsupported = new AiRequest([['role' => 'user', 'content' => 'synthetic']], 'gigachat', 0.5);
                app(AiGateway::class)->complete($unsupported, $this->context($scope, 'unsupported-model'));
                self::fail('Planned model accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('not active', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->where('organization_id', $scope[0])->count());
        });
        Http::assertNothingSent();

        $fakeScope = $this->scope();
        $directory = storage_path('app/fake-'.Str::random(12));
        mkdir($directory, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory]);
        $path = $directory.'/'.CanonicalJson::hash($this->request()->toArray()).'.json';
        file_put_contents($path, json_encode(['result' => ['content' => 'fake result', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $this->request()->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
        try {
            $this->within($fakeScope, function () use ($fakeScope): void {
                $result = app(AiGateway::class)->complete($this->request(), $this->context($fakeScope, 'fake-success'));
                self::assertSame('fake result', $result->content);
                self::assertSame(0, $result->costMicros);
                self::assertSame('completed', DB::table('ai_calls')->where('organization_id', $fakeScope[0])->value('status'));
            });
        } finally {
            unlink($path);
            rmdir($directory);
        }
        Http::assertNothingSent();
    }
}
