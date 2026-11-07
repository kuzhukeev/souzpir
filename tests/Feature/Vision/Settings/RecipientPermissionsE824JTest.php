<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RecipientPermissionsE824JTest extends TenancyTestCase
{
    public function test_recipient_permissions_follow_the_current_policy_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$otherBrand] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $members = collect($this->get('/vision/settings', ['X-Inertia-Partial-Component' => 'Vision/Settings/Index', 'X-Inertia-Partial-Data' => 'members'])->assertOk()->inertiaProps('members'))->keyBy('id');
        self::assertTrue($members[$owner->id]['canReadAnswers']);
        self::assertTrue($members[$viewer->id]['canReadAnswers']);
        self::assertFalse($members[$manager->id]['canReadAnswers']);
        self::assertFalse($members->has($otherBrand->id));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $otherMembers = collect($this->get('/vision/settings', ['X-Inertia-Partial-Component' => 'Vision/Settings/Index', 'X-Inertia-Partial-Data' => 'members'])->assertOk()->inertiaProps('members'))->keyBy('id');
        self::assertTrue($otherMembers[$otherBrand->id]['canReadAnswers']);
        self::assertFalse($otherMembers->has($viewer->id));
    }
}
