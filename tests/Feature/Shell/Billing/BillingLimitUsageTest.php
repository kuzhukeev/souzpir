<?php

namespace Tests\Feature\Shell\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BillingLimitUsageTest extends TenancyTestCase
{
    public function test_confirmed_limit_usage_is_scoped_to_organization_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'usage-test', 'name' => 'Тестовый план', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['brands.max' => '4', 'seller.dialogs_month' => '5'] as $key => $value) {
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => $key,
                'value' => $value, 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([$brand, $other] as $item) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $limits = collect($this->get('/billing')->assertOk()->inertiaProps('limits'));
        self::assertSame(2, $limits->firstWhere('key', 'brands.max')['used']);
        self::assertSame(1, $limits->firstWhere('key', 'seller.dialogs_month')['used']);

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $limits = collect($this->get('/billing')->assertOk()->inertiaProps('limits'));
        self::assertSame(1, $limits->firstWhere('key', 'seller.dialogs_month')['used']);
    }
}
