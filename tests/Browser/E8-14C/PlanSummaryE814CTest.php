<?php

namespace Tests\Feature\Vision\Profile;

use App\Shell\Billing\VisionPlanSummary;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PlanSummaryE814CTest extends TenancyTestCase
{
    public function test_start_displays_only_authorized_current_organization_plan_and_limits(): void
    {
        config(['app.asset_url' => 'http://127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        [, $foreignOrg] = $this->cabinet(false);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'e814c', 'name' => 'Тестовый план', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'vision.questions', 'value' => 30, 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision')->assertOk()->assertInertia(fn (Assert $page) => $page->where('plan.name', 'Тестовый план')->where('plan.questions', '30.0000')->where('plan.runs_per_week', null));
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->get('/vision')->assertOk()->assertInertia(fn (Assert $page) => $page->where('plan', null));
        self::assertNull(app(VisionPlanSummary::class)->read(new TenantContext($foreignOrg->id))['name']);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision')->assertOk()->assertInertia(fn (Assert $page) => $page->where('plan.name', 'Тестовый план'));
        $this->post('/logout');
        $this->get('/vision')->assertRedirect('/login');
    }
}
