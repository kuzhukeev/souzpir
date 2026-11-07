<?php

namespace Tests\Feature\Launch\L1_01;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Live\LiveDraftJob;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LiveDraftBudgetStopTest extends TenancyTestCase
{
    public function refreshDatabase(): void
    {
        // Gateway's live driver verifies the call outside a database transaction.
        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');
        parent::tearDown();
    }

    public function test_budget_exhausted_fails_the_turn_and_hands_off_to_a_manager(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(
            ['ai_data_policy' => ['conversation_content' => ['any'], 'knowledge_internal' => ['any']]], JSON_THROW_ON_ERROR
        )]);
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [],
            'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
        app(TenantContextRunner::class)->run($context, function () use ($context, $source): void {
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($context, $source);
            $repo->publish($context, $draft['id'], 1);
        });
        $conversationId = (string) Str::ulid();
        $messageId = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversationId, 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'mode' => 'suggest',
            'stage' => 'qualify', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $messageId, 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'conversation_id' => $conversationId,
            'role' => 'client', 'text' => 'Сколько стоит доставка?', 'created_at' => now(), 'updated_at' => now()]);
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only',
            'features.LV-01.defaults.enabled' => true, 'features.L1-01.defaults.global_daily_usd' => 0.00001]);
        Http::preventStrayRequests();
        Http::fake();

        (new LiveDraftJob($organization->id, $brand->id, $conversationId, $messageId))->handle();

        $turn = DB::table('seller_turns')->where('conversation_id', $conversationId)->first();
        self::assertNotNull($turn);
        self::assertSame('failed', $turn->status);
        $outcome = json_decode($turn->outcome, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('budget_exhausted', $outcome['error_code']);
        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        self::assertSame('manager', $conversation->mode);
        self::assertSame('handoff', $conversation->stage);
        self::assertSame('handoff', $conversation->outcome);
        self::assertNotNull($conversation->handed_off_at);
        self::assertSame(1, DB::table('seller_stage_events')->where('conversation_id', $conversationId)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.dialog.budget_handoff')->count());
        Http::assertNothingSent();
    }
}
