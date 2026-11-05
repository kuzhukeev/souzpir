<?php

namespace Tests\Feature\Shell\Organizations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ModuleDatesE104CTest extends TenancyTestCase
{
    public function test_modules_show_dates_for_the_target_brand_and_enforce_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [, $foreignOrg, $foreign] = $this->cabinet(false);
        foreach ([[$org, $brand, '2026-01-15 10:00:00'], [$org, $other, '2026-02-16 10:00:00'], [$foreignOrg, $foreign, '2026-03-17 10:00:00']] as [$o, $b, $date]) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $o->id, 'brand_id' => $b->id, 'module' => 'vision', 'enabled' => true, 'created_at' => $date, 'updated_at' => $date]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/brands/'.$brand->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page
            ->where('items.vision.enabled_at', fn ($date) => str_starts_with($date, '2026-01-15'))
            ->where('items.seller.enabled_at', null));
        $this->get('/brands/'.$other->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page
            ->where('items.vision.enabled_at', fn ($date) => str_starts_with($date, '2026-02-16')));
        $this->get('/brands/'.$foreign->id.'/modules')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/brands/'.$brand->id.'/modules')->assertForbidden();
    }

    public function test_invalid_module_and_invalid_value_are_rejected(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/brands/'.$brand->id.'/modules/unknown', ['enabled' => true])->assertNotFound();
        $this->put('/brands/'.$brand->id.'/modules/vision', ['enabled' => 'invalid'])->assertSessionHasErrors('enabled');
    }
}
