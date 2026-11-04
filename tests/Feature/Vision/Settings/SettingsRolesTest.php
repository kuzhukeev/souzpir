<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SettingsRolesTest extends TenancyTestCase
{
    public function test_recipient_roles_are_scoped_to_the_selected_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$local] = $this->member($org, 'viewer', [$brand->id]);
        [$foreign] = $this->member($org, 'viewer', [$other->id]);
        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/settings')->assertOk();
        $members = collect($response->inertiaProps('members'));
        $this->assertSame('viewer', $members->firstWhere('id', $local->id)['role']);
        $this->assertNull($members->firstWhere('id', $foreign->id));
        $response = $this->actingAs($local)->withSession(['brand_id' => $brand->id])->get('/vision/settings')->assertOk();
        $response->assertInertia(fn ($page) => $page->where('canManageRecipients', false));
    }
}
