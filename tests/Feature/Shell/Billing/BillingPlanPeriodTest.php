<?php

namespace Tests\Feature\Shell\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BillingPlanPeriodTest extends TenancyTestCase
{
    public function test_plan_period_comes_from_current_assignment_and_is_not_shared_between_organizations(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [, $otherOrg] = $this->cabinet(false);
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'period-test', 'name' => 'План', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $otherOrg->id, 'plan_id' => $plan, 'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(20), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentPlan.id', $plan)->where('currentPlan.startsAt', fn ($value) => str_starts_with($value, now()->subDay()->toDateString()))->where('currentPlan.endsAt', fn ($value) => str_starts_with($value, now()->addDays(3)->toDateString())));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/billing/plans')->assertForbidden();
    }
}
