<?php

namespace Tests\Feature\Shell\Organizations;

use Tests\Feature\Tenancy\TenancyTestCase;

final class ModuleConnectE104HTest extends TenancyTestCase
{
    public function test_connect_respects_rights_brand_and_input(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [, , $foreign] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => false])->assertSessionHasNoErrors();
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('brand_modules', ['brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true]);
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => 'bad'])->assertSessionHasErrors('enabled');
        $this->put('/brands/'.$foreign->id.'/modules/seller', ['enabled' => true])->assertNotFound();
        $this->get('/brands/'.$other->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page->where('targetBrand.id', $other->id));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertForbidden();
    }
}
