<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\ProtectedOperations\SellerSimulation\ProtectedSimulationRuntime;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

/** D-5.6: Policy handoff (off-topic streak / negative-sentiment / want_human) reaching the protected finalizers. */
final class HandoffFinalizersE603LTest extends TenancyTestCase
{
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        config(['runtime.ai_driver' => 'fake', 'cache.default' => 'array']);
        app(PromptSetsSeeder::class)->run();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function cabinetWithKnowledge(): array
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));

        return [$owner, $org, $brand, $other, $membership];
    }

    public function test_followup_finalizer_hands_off_on_explicit_want_human_and_isolates_brand(): void
    {
        [, $org, $brand] = $this->cabinetWithKnowledge();
        $this->app->bind(FakeDriver::class, fn () => new class implements Driver
        {
            public function complete(#[\SensitiveParameter] array $body): array
            {
                return ['content' => isset($body['response_format']) ? '{"client_intent":"want_human"}' : 'Передаю менеджеру, он напишет вам.',
                    'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        });
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'handoff-open');
        $reply = $runtime->turn($tenant, $opened['conversation_id'], 'Хочу живого человека', 'handoff-turn');
        self::assertSame('Передаю менеджеру, он напишет вам.', $reply['agent']);
        $conversation = DB::table('conversations')->where('id', $opened['conversation_id'])->first();
        self::assertSame('manager', $conversation->mode);
        self::assertSame('handoff', $conversation->stage);
        self::assertSame('handoff', $conversation->outcome);
        self::assertNotNull($conversation->handed_off_at);
        self::assertSame(2, (int) $conversation->version);
        self::assertSame($reply, $runtime->turn($tenant, $opened['conversation_id'], 'Хочу живого человека', 'handoff-turn'));
        Http::assertNothingSent();
    }

    public function test_foreign_brand_cannot_trigger_handoff_for_another_brands_conversation(): void
    {
        [, $org, $brand, $other] = $this->cabinetWithKnowledge();
        $this->app->bind(FakeDriver::class, fn () => new class implements Driver
        {
            public function complete(#[\SensitiveParameter] array $body): array
            {
                return ['content' => isset($body['response_format']) ? '{"client_intent":"want_human"}' : 'Передаю менеджеру, он напишет вам.',
                    'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                    'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
            }
        });
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'handoff-isolation-open');
        try {
            $runtime->turn(new TenantContext($org->id, $other->id, source: 'job'), $opened['conversation_id'], 'Хочу живого человека', 'handoff-isolation-turn');
            self::fail('Foreign brand reached another brand conversation.');
        } catch (IdempotencyFailure) {
            self::assertSame('bot', DB::table('conversations')->where('id', $opened['conversation_id'])->value('mode'));
        }
    }
}
