<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DeferredRecipientsE824LTest extends TenancyTestCase
{
    public function test_members_are_deferred_and_rechecked_for_the_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$local] = $this->member($org, 'viewer', [$brand->id]);
        [$foreign] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->missing('members'));
        $headers = ['X-Inertia-Partial-Component' => 'Vision/Settings/Index', 'X-Inertia-Partial-Data' => 'members'];
        $members = collect($this->get('/vision/settings', $headers)->assertOk()->inertiaProps('members'))->keyBy('id');
        self::assertTrue($members->has($local->id));
        self::assertFalse($members->has($foreign->id));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $members = collect($this->get('/vision/settings', $headers)->assertOk()->inertiaProps('members'))->keyBy('id');
        self::assertTrue($members->has($foreign->id));
        self::assertFalse($members->has($local->id));
        [$outsider] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->actingAs($outsider)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/settings', $headers)->assertForbidden();
    }
}
