<?php

namespace Tests\Feature\Seller\Settings\Tools;

use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\ToolRunner;
use App\Seller\Engine\Tools\EscalateToManager;
use App\Seller\Engine\Tools\ToolCallJournal;
use App\Seller\Runtime\ConfiguredToolHandler;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ToolJournalE604KTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_disabled_toggle_is_journaled_instead_of_executing_the_action(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/scenario/tools', ['tools' => ['check_stock' => false], 'requisites' => '', 'delivery_rules' => []])->assertSessionHasNoErrors();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        self::assertSame(['status' => 'disabled'], app(ToolRunner::class)->run('check_stock', ['query' => 'A1'], $tenant));
        self::assertDatabaseHas('seller_tool_call_journal', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'tool' => 'check_stock', 'status' => 'disabled']);
        self::assertSame(1, DB::table('seller_tool_call_journal')->count());
    }

    public function test_calc_delivery_outcomes_are_journaled_and_isolated_by_brand(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            $this->enable($org->id, $item->id);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/scenario/tools', ['tools' => ['calc_delivery' => true], 'requisites' => '', 'delivery_rules' => [['city' => 'Москва', 'price_min' => 100, 'price_max' => 200, 'days_min' => 1, 'days_max' => 2]]])->assertSessionHasNoErrors();
        $handler = app(ConfiguredToolHandler::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $otherTenant = new TenantContext($org->id, $other->id, source: 'job');
        self::assertSame('ready', $handler->calculateDelivery($tenant, ['city' => 'Москва'])['status']);
        self::assertSame('invalid_arguments', $handler->calculateDelivery($tenant, ['city' => 1])['status']);
        self::assertSame(1, DB::table('seller_tool_call_journal')->where('brand_id', $brand->id)->where('status', 'ready')->count());
        self::assertSame(0, DB::table('seller_tool_call_journal')->where('brand_id', $other->id)->count());
        self::assertSame('not_configured', $handler->calculateDelivery($otherTenant, ['city' => 'Москва'])['status']);
        self::assertSame(1, DB::table('seller_tool_call_journal')->where('brand_id', $other->id)->where('status', 'not_configured')->count());
    }

    public function test_escalate_retry_with_the_same_turn_does_not_double_count(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $conversationId = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversationId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = new TurnClaim('claimed', (string) Str::ulid(), $conversationId, $org->id, $brand->id, 0);
        $escalate = app(EscalateToManager::class);
        $first = $escalate->run($tenant, $claim, (string) Str::ulid(), 'tool-1', ['reason' => 'клиент просит менеджера']);
        $second = $escalate->run($tenant, $claim, (string) Str::ulid(), 'tool-1', ['reason' => 'клиент просит менеджера']);
        self::assertSame('completed', $first['status']);
        self::assertSame($first, $second);
        self::assertSame(1, DB::table('seller_tool_call_journal')->where('tool', 'escalate')->count());
        self::assertSame(2, DB::table('seller_tasks')->where('type', 'handoff')->count());
    }

    public function test_tools_screen_counts_only_the_last_30_days_from_the_journal(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $journal = app(ToolCallJournal::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        DB::table('seller_tool_call_journal')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'tool' => 'check_stock', 'status' => 'completed', 'created_at' => now()->subDays(31)]);
        self::assertSame(0, $journal->countSince($tenant, 'check_stock', now()->subDays(30)));
        DB::table('seller_tool_call_journal')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'tool' => 'check_stock', 'status' => 'completed', 'created_at' => now()->subDays(2)]);
        self::assertSame(1, $journal->countSince($tenant, 'check_stock', now()->subDays(30)));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.check_stock', 1));
    }
}
