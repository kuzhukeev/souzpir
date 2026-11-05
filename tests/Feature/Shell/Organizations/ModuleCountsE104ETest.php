<?php

namespace Tests\Feature\Shell\Organizations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ModuleCountsE104ETest extends TenancyTestCase
{
    public function test_channel_counts_are_scoped_to_the_target_brand_and_require_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [, $foreignOrg, $foreign] = $this->cabinet(false);
        foreach ([[$org, $brand, 'active'], [$org, $brand, 'unconfigured'], [$org, $other, 'active'], [$foreignOrg, $foreign, 'active']] as [$organization, $target, $status]) {
            DB::table('channel_accounts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $target->id, 'type' => 'telegram', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/brands/'.$brand->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page->where('counts.channels', 1)->where('counts.publications', 0));
        $this->get('/brands/'.$other->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page->where('counts.channels', 1));
        $this->get('/brands/'.$foreign->id.'/modules')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/brands/'.$brand->id.'/modules')->assertForbidden();
    }
}
