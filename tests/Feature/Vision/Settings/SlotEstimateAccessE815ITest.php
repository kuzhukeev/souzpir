<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SlotEstimateAccessE815ITest extends TenancyTestCase
{
    public function test_disabled_schedule_is_zero_for_current_brand_and_bad_ranges_are_rejected(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/settings?estimate_from=2027-01-01&estimate_to=2027-01-31')->assertOk()->assertInertia(fn ($page) => $page->where('slotEstimate.period.runs', 0)->where('slotEstimate.period.slots', 0)->where('slotEstimate.from', '2027-01-01'));
        $this->get('/vision/settings?estimate_from=2027-02-01&estimate_to=2027-01-01')->assertSessionHasErrors('estimate_to');
        $this->get('/vision/settings?estimate_from=2027-01-01&estimate_to=2029-01-01')->assertSessionHasErrors('estimate_to');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->where('slotEstimate.period.slots', 0));
        Http::assertNothingSent();
    }
}
