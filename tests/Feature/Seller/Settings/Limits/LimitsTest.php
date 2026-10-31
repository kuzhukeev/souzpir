<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Seller\Settings\Limits\SellerUsage;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LimitsTest extends LimitsTestCase
{
    public function test_real_counts_limits_and_units_are_scoped_without_usd(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->member($org, 'member', [$brand->id]);
        $a = $this->conversation($org->id, $brand->id);
        $b = $this->conversation($org->id, $other->id);
        $this->message($org->id, $brand->id, $a, 'client');
        $this->message($org->id, $brand->id, $a, 'agent');
        $this->message($org->id, $brand->id, $a, 'system');
        $this->message($org->id, $other->id, $b, 'client');
        $this->channel($org->id, $brand->id, 'active');
        $this->channel($org->id, $brand->id, 'disabled');
        $this->channel($org->id, $other->id, 'active');
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'test', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'seller.dialogs_month', 'value' => 5, 'unit' => 'dialogues', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_budgets')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'family' => 'seller', 'period' => now()->startOfMonth()->toDateString(), 'limit_units' => 100, 'used_units' => 12, 'limit_micros' => 777, 'spent_micros' => 123, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Seller/Settings/Limits/Index', false)
                ->where('rows.0.value', 1)->where('rows.0.limit', '5.0000')
                ->where('rows.1.value', 2)->where('rows.1.configured', false)
                ->where('rows.2.value', 1)->where('rows.3.value', 2)
                ->where('budget.used', '12.0000')->where('budget.limit', '100.0000')
                ->missing('budget.limit_micros')->missing('budget.spent_micros'));
    }

    public function test_missing_budget_and_zero_limit_are_not_invented(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'test', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'seller.dialogs_month', 'value' => 0, 'unit' => 'dialogues', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('rows.0.limit', '0.0000')->where('rows.0.configured', true)->where('budget', null));
    }

    public function test_counts_follow_half_open_period_and_do_not_invent_historical_active_counts(): void
    {
        [, $org, $brand] = $this->cabinet();
        $inside = $this->conversation($org->id, $brand->id);
        $outside = $this->conversation($org->id, $brand->id);
        DB::table('conversations')->where('id', $inside)->update(['created_at' => '2024-02-29 23:59:59+00']);
        DB::table('conversations')->where('id', $outside)->update(['created_at' => '2024-03-01 00:00:00+00']);
        $query = new UsageQuery(new CarbonImmutable('2024-02-01T00:00:00Z'), new CarbonImmutable('2024-03-01T00:00:00Z'), ['seller.dialogs_month', 'seller.channels_active', 'org.members_active']);
        $result = app(SellerUsage::class)->read(new TenantContext($org->id, $brand->id), $query);
        self::assertSame(1, $result['seller.dialogs_month']['value']);
        self::assertArrayNotHasKey('seller.channels_active', $result);
        self::assertArrayNotHasKey('org.members_active', $result);
    }

    public function test_foreign_brand_cannot_be_queried_as_another_organization(): void
    {
        [, $org] = $this->cabinet();
        [, $otherOrg, $foreign] = $this->cabinet(false);
        $query = new UsageQuery(CarbonImmutable::now('UTC')->startOfMonth(), CarbonImmutable::now('UTC')->startOfMonth()->addMonth(), ['seller.dialogs_month']);
        try {
            app(SellerUsage::class)->read(new TenantContext($org->id, $foreign->id), $query);
            self::fail('Expected a denied foreign brand.');
        } catch (NotFoundHttpException) {
            self::assertNotSame($org->id, $otherOrg->id);
        }
    }

    public function test_dialogue_usage_contract_and_module_alias_return_same_scoped_count(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        $this->conversation($org->id, $brand->id);
        $this->conversation($org->id, $other->id);
        $query = new UsageQuery(CarbonImmutable::now('UTC')->startOfMonth(), CarbonImmutable::now('UTC')->startOfMonth()->addMonth(), ['seller.dialogues', 'seller.dialogs_month']);
        $result = app(UsageReader::class)->read(new TenantContext($org->id, $brand->id), $query)->metrics;
        self::assertSame(1, $result['seller.dialogues']['value']);
        self::assertSame($result['seller.dialogues'], $result['seller.dialogs_month']);
        $single = new UsageQuery($query->from, $query->to, ['seller.dialogues']);
        self::assertSame(1, app(UsageReader::class)->read(new TenantContext($org->id, $brand->id), $single)->metrics['seller.dialogues']['value']);
    }

    private function conversation(string $org, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function message(string $org, string $brand, string $conversation, string $role): void
    {
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $conversation, 'role' => $role, 'text' => 'fixture', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function channel(string $org, string $brand, string $status): void
    {
        DB::table('channel_accounts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'type' => 'widget', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }
}
