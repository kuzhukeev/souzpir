<?php

namespace Tests\Feature\Shell\Billing;

use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\Contracts\UsageSnapshot;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BillingTariffTermsTest extends TenancyTestCase
{
    public function test_confirmed_plan_terms_are_used_and_missing_terms_remain_unknown(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'terms-test', 'name' => 'План', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(UsageReader::class, new class implements UsageReader
        {
            public function read(TenantContext $context, UsageQuery $query): UsageSnapshot
            {
                return new UsageSnapshot($query->from, ['seller.dialogues' => ['value' => 2, 'unit' => 'диалоги', 'status' => 'ready']]);
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/billing/usage?from=2026-10-01&to=2026-10-01';
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('daily.rows.0.tariffUnits', null)->where('daily.rows.0.tariffCost', null)->where('currentPlan.renewsAt', null));
        config(['billing.plans' => [$plan => ['units_per_event' => ['seller.dialogues' => 3], 'unit_price' => 2]], 'billing.renewals' => [$org->id => [$plan => '2026-12-02']]]);
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('daily.rows.0.tariffUnits', 6)->where('daily.rows.0.tariffCost', 12)->where('currentPlan.renewsAt', '2026-12-02'));
        $csv = $this->get('/billing/usage/export?from=2026-10-01&to=2026-10-01')->assertOk()->streamedContent();
        self::assertStringContainsString('ИИ-продавец', $csv);
        self::assertStringContainsString(';6;12', $csv);
        config(['billing.plans' => [$plan => ['units_per_event' => ['seller.dialogues' => 0], 'unit_price' => 2]], 'billing.renewals' => ['another-organization' => [$plan => '2026-12-03']]]);
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('daily.rows.0.tariffUnits', 0)->where('daily.rows.0.tariffCost', 0)->where('currentPlan.renewsAt', null));
    }
}
