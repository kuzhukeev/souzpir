<?php

namespace Tests\Feature\Shell\Organizations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ModuleRetentionE104GTest extends TenancyTestCase
{
    public function test_switching_seller_preserves_saved_data_and_limits_access(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00+00');
        try {
            [$owner, $org, $brand, $other] = $this->cabinet();
            $id = (string) Str::ulid();
            DB::table('seller_personas')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => 'retention', 'name' => 'Сценарий', 'type' => 'newbie', 'payload' => '{}', 'max_turns' => 2, 'created_at' => now(), 'updated_at' => now()]);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => false])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('seller_personas', ['id' => $id, 'brand_id' => $brand->id]);
            $this->get('/brands/'.$other->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page->where('items.seller.disabled_at', null));
            $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('seller_personas', ['id' => $id, 'brand_id' => $brand->id]);
            $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => 'bad'])->assertSessionHasErrors('enabled');
            [$viewer] = $this->member($org, 'viewer', [$brand->id]);
            $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => false])->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }
}
