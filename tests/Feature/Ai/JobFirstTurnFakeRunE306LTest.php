<?php

namespace Tests\Feature\Ai;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\ProtectedOperations\SellerFollowup\FollowupCoordinator;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JobFirstTurnFakeRunE306LTest extends TenancyTestCase
{
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->app->bind(FakeDriver::class, fn () => new class implements Driver
        {
            public function complete(#[\SensitiveParameter] array $body): array
            {
                return ['content' => isset($body['response_format']) ? '{"client_intent":"answer_only"}' : 'Добрый день! Чем можем помочь?',
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

    private function seeded(): array
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake', 'features.E0-02.defaults.organization_id' => $org->id]);
        app(PromptSetsSeeder::class)->run();
        $ownerTenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($ownerTenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($ownerTenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $org, $brand, $other, $member];
    }

    private function firstMessage(string $org, string $brand): array
    {
        $tenant = new TenantContext($org, $brand, source: 'job');

        return app(TenantContextRunner::class)->run($tenant, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Здравствуйте, подскажите цены', 'meta' => []]);

            return [$conversation, $message];
        });
    }

    public function test_full_fake_turn_completes_and_recovers_without_duplicate_ai_calls(): void
    {
        Http::preventStrayRequests();
        [, $org, $brand] = $this->seeded();
        [$conversation, $message] = $this->firstMessage($org->id, $brand->id);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');

        $result = app(FollowupCoordinator::class)->runFirstTurnJob($tenant, $conversation->id, $message->id, 'first-turn-job-1');

        self::assertSame('completed', $result['status']);
        self::assertIsString($result['result']['message_id']);
        self::assertSame(1, $result['result']['version']);
        self::assertSame('completed', DB::table('seller_turns')->where('conversation_id', $conversation->id)->value('status'));
        self::assertSame(1, DB::table('conversations')->where('id', $conversation->id)->value('version'));
        self::assertSame(2, DB::table('messages')->where('conversation_id', $conversation->id)->count());
        $callCount = DB::table('ai_calls')->where('brand_id', $brand->id)->where('status', 'completed')->count();

        // Stage recovery: the same job call replays the already-completed turn, without new AI calls or messages.
        $replayed = app(FollowupCoordinator::class)->runFirstTurnJob($tenant, $conversation->id, $message->id, 'first-turn-job-1');
        self::assertSame($result['result'], $replayed['result']);
        self::assertSame(2, DB::table('messages')->where('conversation_id', $conversation->id)->count());
        self::assertSame($callCount, DB::table('ai_calls')->where('brand_id', $brand->id)->where('status', 'completed')->count());
        Http::assertNothingSent();
    }

    public function test_brand_isolation_and_errors_are_rejected(): void
    {
        [, $org, $brand, $other] = $this->seeded();
        [$conversation, $message] = $this->firstMessage($org->id, $brand->id);

        try {
            app(FollowupCoordinator::class)->runFirstTurnJob(new TenantContext($org->id, $other->id, source: 'job'), $conversation->id, $message->id, 'cross-brand');
            self::fail('Foreign brand accepted');
        } catch (LogicException) {
            self::assertSame(0, DB::table('seller_turns')->count());
        }

        try {
            app(FollowupCoordinator::class)->runFirstTurnJob(new TenantContext($org->id, $brand->id, source: 'session'), $conversation->id, $message->id, 'wrong-source');
            self::fail('Session source accepted');
        } catch (LogicException) {
            self::assertSame(0, DB::table('seller_turns')->count());
        }

        try {
            app(FollowupCoordinator::class)->runFirstTurnJob(new TenantContext($org->id, $brand->id, source: 'job'), 'not-a-ulid', $message->id, 'bad-id');
            self::fail('Invalid conversation id accepted');
        } catch (LogicException) {
            self::assertSame(0, DB::table('seller_turns')->count());
        }

        config(['runtime.ai_driver' => 'live']);
        try {
            app(FollowupCoordinator::class)->runFirstTurnJob(new TenantContext($org->id, $brand->id, source: 'job'), $conversation->id, $message->id, 'live-rejected');
            self::fail('Live driver accepted');
        } catch (LogicException) {
            self::assertSame(0, DB::table('seller_turns')->count());
        }
    }
}
