<?php

namespace Tests\Feature\Seller\Settings\Limits;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

final class LimitsOverviewBTest extends LimitsTestCase
{
    public function test_period_remaining_and_tariff_link_are_scoped_to_current_brand_and_role(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03T12:00:00Z'));
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Seller/Settings/Limits/Index', false)
                ->where('daysRemaining', 28)->where('period.to', '2026-12-01')->where('canViewPlans', true));

        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewPlans', false)->where('daysRemaining', 28));

        $this->actingAs($editor)->withSession(['brand_id' => $other->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('brand.id', $brand->id));

        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => false]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertStatus(404);
    }
}
