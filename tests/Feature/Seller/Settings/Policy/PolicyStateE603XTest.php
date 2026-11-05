<?php

namespace Tests\Feature\Seller\Settings\Policy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PolicyStateE603XTest extends TenancyTestCase
{
    public function test_followup_access_validation_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $input = ['enabled' => true, 'first_hours' => 4, 'second_hours' => 8, 'max_auto' => 2, 'window' => null, 'texts' => ['Напоминание'], 'agreed_text' => ''];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/settings/followups', $input)->assertSessionHasNoErrors();
        $this->post('/seller/settings/followups', [...$input, 'first_hours' => 0])->assertSessionHasErrors('first_hours');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('followup.first_hours', 20));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/followups', $input)->assertForbidden();
    }
}
