<?php

namespace Tests\Feature\Shell\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamMemberWorkloadTest extends TenancyTestCase
{
    public function test_workload_and_notification_channels_are_current_brand_only(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$target, $member] = $this->member($org, 'admin', [$brand->id, $other->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'assigned_user_id' => $target->id, 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $target->id, 'event_key' => 'vision.measurement.completed', 'channel' => 'email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('dialogCount', 1)->where('receives', ['vision.measurement.completed' => ['cabinet', 'email'], 'seller.conversation.handed_off' => ['cabinet']]));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('receives', []));
        [$outsider] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($outsider)->withSession(['brand_id' => $brand->id])->get('/team/'.$member->id)->assertForbidden();
    }
}
