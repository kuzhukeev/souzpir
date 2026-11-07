<?php

namespace Tests\Feature\Seller\Followups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupSettingsE603OTest extends TenancyTestCase
{
    public function test_stage_settings_validate_permissions_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $input = ['enabled' => true, 'first_hours' => 20, 'second_hours' => null, 'max_auto' => 2, 'window' => null, 'texts' => [], 'agreed_text' => '', 'excluded_stages' => ['need']];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/settings/followups', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('followup.excluded_stages.0', 'need'));
        $this->post('/seller/settings/followups', [...$input, 'excluded_stages' => ['invalid']])->assertSessionHasErrors('excluded_stages.0');
        $this->post('/seller/settings/followups', [...$input, 'excluded_stages' => ['need', 'need']])->assertSessionHasErrors();
        $legacy = $input;
        unset($legacy['excluded_stages']);
        $this->post('/seller/settings/followups', $legacy)->assertSessionHasNoErrors();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('followup.excluded_stages.0', 'need'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/followups', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('followup.excluded_stages', []));
    }
}
