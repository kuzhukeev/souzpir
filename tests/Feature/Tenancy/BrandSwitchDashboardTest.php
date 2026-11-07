<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Http;

final class BrandSwitchDashboardTest extends TenancyTestCase
{
    public function test_switch_opens_dashboard_with_only_the_new_brand(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('brand.id', $first->id));

        $this->post('/brand/switch', ['brand_id' => $second->id], ['X-Context-Change-Key' => 'dashboard-switch-key-123456'])->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('brand.id', $second->id));
        $this->withHeader('X-Brand-Id', $first->id)->getJson('/dashboard')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_denied_brand_leaves_dashboard_on_authorized_brand(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $this->postJson('/brand/switch', ['brand_id' => $second->id])->assertNotFound();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('brand.id', $first->id));
    }
}
