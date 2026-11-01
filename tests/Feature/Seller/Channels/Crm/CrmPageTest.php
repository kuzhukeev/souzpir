<?php

namespace Tests\Feature\Seller\Channels\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class CrmPageTest extends LimitsTestCase
{
    public function test_page_is_available_for_seller_brand_and_has_no_delivery_endpoint(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->withHeader('X-Brand-Id', $first->id);
        $this->get('/seller/integrations/crm')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Seller/Channels/Crm/Index'));
        $this->post('/seller/integrations/crm', ['url' => 'https://example.test'])->assertMethodNotAllowed();
        DB::table('brand_modules')->where('brand_id', $second->id)->where('module', 'seller')->update(['enabled' => false]);
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $second->id);
        $this->get('/seller/integrations/crm')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_page_requires_authorized_active_membership(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->get('/seller/integrations/crm')->assertRedirect('/login');
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)->get('/seller/integrations/crm')->assertForbidden();
    }
}
