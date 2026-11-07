<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use App\Ai\Drivers\Uncertain;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\ProtectedOperations\SellerSimulation\ProtectedSimulationRuntime;
use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProtectedSimulationRuntimeTest extends TenancyTestCase
{
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        config(['runtime.ai_driver' => 'fake', 'cache.default' => 'array']);
        app(PromptSetsSeeder::class)->run();
        $this->app->bind(FakeDriver::class, fn () => new class implements Driver
        {
            public function complete(#[\SensitiveParameter] array $body): array
            {
                if (DB::transactionLevel() !== 0) {
                    throw new LogicException('Driver was called in a transaction.');
                }

                return ['content' => isset($body['response_format']) ? '{"client_intent":"answer_only"}' : 'Хорошо, спасибо!',
                    'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        });
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function simulationCabinet(): array
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));

        return [$owner, $org, $brand, $other, $membership];
    }

    public function test_job_runs_persona_through_protected_fake_conversation(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, , $membership] = $this->simulationCabinet();
        self::assertSame(29, app(SellerPersonaSeeder::class)->seedForBrand(new TenantContext($org->id, $brand->id, $owner->id, $membership->id)));
        SimulateFakeJob::dispatchSync($org->id, $brand->id, 'newbie_voronezh', [[], [], []]);

        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        self::assertSame('completed', $run->status);
        self::assertSame(3, $run->turn_count);
        self::assertSame(1, DB::table('conversations')->where('brand_id', $brand->id)->count());
        self::assertSame(5, DB::table('messages')->where('brand_id', $brand->id)->count());
        self::assertSame(2, DB::table('seller_turns')->where('brand_id', $brand->id)->count());
        Http::assertNothingSent();
    }

    public function test_confirmed_fake_failures_can_be_retried_without_duplicate_messages(): void
    {
        [, $org, $brand, $other] = $this->simulationCabinet();
        $driver = new class implements Driver
        {
            private array $failed = [];

            public function complete(#[\SensitiveParameter] array $body): array
            {
                $stage = isset($body['response_format']) ? 'extractor' : 'responder';
                if (! isset($this->failed[$stage])) {
                    $this->failed[$stage] = true;
                    throw new Rejected('Synthetic unbilled failure.');
                }

                return ['content' => $stage === 'extractor' ? '{"client_intent":"answer_only"}' : 'Хорошо, спасибо!',
                    'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        };
        $this->app->instance(FakeDriver::class, $driver);
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'retry-open');
        for ($i = 0; $i < 2; $i++) {
            try {
                $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'retry-turn');
                self::fail('Failed attempt unexpectedly completed.');
            } catch (LogicException) {
                self::assertSame(2, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
            }
        }
        $reply = $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'retry-turn');
        self::assertSame('Хорошо, спасибо!', $reply['agent']);
        self::assertSame($reply, $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'retry-turn'));
        self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
        self::assertSame(2, DB::table('ai_calls')->where('brand_id', $brand->id)->where('status', 'failed')->count());
        self::assertSame(2, DB::table('ai_calls')->where('brand_id', $brand->id)->where('status', 'completed')->where('attempt', 2)->whereNotNull('parent_call_id')->count());
        try {
            $runtime->turn(new TenantContext($org->id, $other->id, source: 'job'), $opened['conversation_id'], 'Воронеж', 'retry-turn');
            self::fail('Foreign brand accepted.');
        } catch (IdempotencyFailure) {
            self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
        }
    }

    public function test_uncertain_fake_call_is_not_retried(): void
    {
        [, $org, $brand] = $this->simulationCabinet();
        $driver = new class implements Driver
        {
            public int $calls = 0;

            public function complete(#[\SensitiveParameter] array $body): array
            {
                $this->calls++;
                throw new Uncertain('Synthetic uncertain result.');
            }
        };
        $this->app->instance(FakeDriver::class, $driver);
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'uncertain-open');
        for ($i = 0; $i < 2; $i++) {
            try {
                $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'uncertain-turn');
                self::fail('Uncertain attempt unexpectedly completed.');
            } catch (LogicException) {
                self::assertSame(2, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
            }
        }
        self::assertSame(1, $driver->calls);
        self::assertSame(1, DB::table('ai_calls')->where('brand_id', $brand->id)->where('status', 'uncertain')->count());
    }

    public function test_model_guard_must_approve_protected_followup(): void
    {
        [, $org, $brand] = $this->simulationCabinet();
        config(['agent.guard_llm' => true]);
        $driver = new class implements Driver
        {
            public bool $allow = true;

            public function complete(#[\SensitiveParameter] array $body): array
            {
                $schema = $body['response_format']['json_schema']['name'] ?? null;
                $content = match ($schema) {
                    'extraction' => '{"client_intent":"unclear","unknown_question":"Есть особый товар?"}',
                    'guard' => json_encode(['ok' => $this->allow, 'issues' => $this->allow ? [] : ['Неподтверждённый факт']], JSON_UNESCAPED_UNICODE),
                    default => 'Я уточню эту информацию.',
                };

                return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        };
        $this->app->instance(FakeDriver::class, $driver);
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $accepted = $runtime->open($tenant, 'guard-open-ok');
        $answer = $runtime->turn($tenant, $accepted['conversation_id'], 'Есть особый товар?', 'guard-turn-ok');
        self::assertSame('Я уточню эту информацию.', $answer['agent']);
        self::assertSame(1, DB::table('ai_calls')->where('object_id', $accepted['conversation_id'])->where('purpose', 'guard')->where('status', 'completed')->count());
        $driver->allow = false;
        $rejected = $runtime->open($tenant, 'guard-open-no');
        try {
            $runtime->turn($tenant, $rejected['conversation_id'], 'Есть особый товар?', 'guard-turn-no');
            self::fail('Model guard rejection was ignored.');
        } catch (LogicException) {
            self::assertSame(1, DB::table('messages')->where('conversation_id', $rejected['conversation_id'])->where('role', 'agent')->count());
            // Bounded regeneration retries the model guard across rounds before safely failing closed.
            self::assertSame(3, DB::table('ai_calls')->where('object_id', $rejected['conversation_id'])->where('purpose', 'guard')->count());
        }
    }

    public function test_model_guard_regenerates_and_recovers_after_a_rejection(): void
    {
        [, $org, $brand] = $this->simulationCabinet();
        config(['agent.guard_llm' => true]);
        $driver = new class implements Driver
        {
            public int $guardCalls = 0;

            public function complete(#[\SensitiveParameter] array $body): array
            {
                $schema = $body['response_format']['json_schema']['name'] ?? null;
                if ($schema === 'guard') {
                    $this->guardCalls++;
                    $ok = $this->guardCalls >= 2;

                    return ['content' => json_encode(['ok' => $ok, 'issues' => $ok ? [] : ['Неподтверждённый факт']], JSON_UNESCAPED_UNICODE),
                        'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                        'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
                }
                $content = $schema === 'extraction' ? '{"client_intent":"unclear","unknown_question":"Есть особый товар?"}' : 'Я уточню эту информацию.';

                return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        };
        $this->app->instance(FakeDriver::class, $driver);
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'guard-recover-open');
        $answer = $runtime->turn($tenant, $opened['conversation_id'], 'Есть особый товар?', 'guard-recover-turn');
        self::assertSame('Я уточню эту информацию.', $answer['agent']);
        self::assertSame(2, $driver->guardCalls);
        self::assertSame(2, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->where('role', 'agent')->count());
        self::assertSame(2, DB::table('conversations')->where('id', $opened['conversation_id'])->value('version'));
    }

    public function test_simulation_does_not_save_unsafe_response(): void
    {
        [, $org, $brand] = $this->simulationCabinet();
        $this->app->bind(FakeDriver::class, fn () => new class implements Driver
        {
            public function complete(#[\SensitiveParameter] array $body): array
            {
                return ['content' => isset($body['response_format']) ? '{"client_intent":"answer_only"}' : 'Гарантируем скидку 99 процентов!',
                    'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        });
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'unsafe-open');
        try {
            $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'unsafe-turn');
            self::fail('Unsafe answer was accepted.');
        } catch (LogicException) {
            self::assertSame(1, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->where('role', 'agent')->count());
            self::assertSame(1, DB::table('conversations')->where('id', $opened['conversation_id'])->value('version'));
        }
    }

    public function test_job_entrypoint_checks_brand_key_and_input(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->simulationCabinet();
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'open-1');
        self::assertSame($opened, $runtime->open($tenant, 'open-1'));
        $reply = $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'turn-1');
        self::assertSame($reply, $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'turn-1'));
        self::assertSame('Хорошо, спасибо!', $reply['agent']);
        self::assertSame(2, DB::table('ai_calls')->where('brand_id', $brand->id)->where('status', 'completed')->count());
        self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());

        try {
            $runtime->turn($tenant, $opened['conversation_id'], 'Другой текст', 'turn-1');
            self::fail('Changed input was accepted for the same key.');
        } catch (IdempotencyConflict) {
            self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
        }
        try {
            $runtime->turn(new TenantContext($org->id, $other->id, source: 'job'), $opened['conversation_id'], 'Воронеж', 'other-turn');
            self::fail('Foreign brand was accepted.');
        } catch (IdempotencyFailure) {
            self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
        }
        try {
            $runtime->open(new TenantContext($org->id, $brand->id, source: 'session'), 'session-open');
            self::fail('Session context was accepted.');
        } catch (LogicException) {
            self::assertSame(1, DB::table('conversations')->where('brand_id', $brand->id)->count());
        }
        $this->expectException(InvalidArgumentException::class);
        $runtime->turn($tenant, $opened['conversation_id'], '', 'bad-turn');
    }
}
