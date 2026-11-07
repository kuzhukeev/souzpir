<?php

namespace Tests\Feature\Seller\Settings\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HandoffE604CTest extends TenancyTestCase
{
    public function test_timeout_options_are_saved_per_brand_and_access_is_restricted(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/handoff')->assertOk()->assertInertia(fn ($page) => $page->where('settings.timeout_notification', false)->where('settings.timeout_fallback', ''));
        $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 10, 'timeout_notification' => true, 'timeout_reminder' => true, 'timeout_fallback' => 'Менеджер ответит позже'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/handoff')->assertOk()->assertInertia(fn ($page) => $page->where('settings.timeout_notification', true)->where('settings.timeout_reminder', true)->where('settings.timeout_fallback', 'Менеджер ответит позже'));
        $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 11])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/handoff')->assertOk()->assertInertia(fn ($page) => $page->where('settings.timeout_minutes', 11)->where('settings.timeout_notification', true));
        $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 0])->assertSessionHasErrors('timeout_minutes');
        $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_fallback' => str_repeat('x', 1001)])->assertSessionHasErrors('timeout_fallback');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/handoff')->assertOk()->assertInertia(fn ($page) => $page->where('settings.timeout_notification', false)->where('settings.timeout_fallback', ''));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/handoff')->assertForbidden();
        $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_notification' => false])->assertForbidden();
    }
}
