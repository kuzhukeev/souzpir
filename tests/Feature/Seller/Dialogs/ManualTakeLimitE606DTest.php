<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ManualTakeLimitE606DTest extends TenancyTestCase
{
    public function test_manual_take_checks_current_brand_capacity_and_permissions(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'payload' => json_encode(['assignment' => ['members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => 1]]]]), 'created_at' => now(), 'updated_at' => now()]);
        $owned = (string) Str::ulid();
        $waiting = (string) Str::ulid();
        $foreign = (string) Str::ulid();
        foreach ([[$owned, $brand->id, $owner->id], [$waiting, $brand->id, null], [$foreign, $other->id, null]] as [$id, $brandId, $assignee]) {
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'stage' => 'open', 'mode' => 'manager', 'assigned_user_id' => $assignee, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/dialogs/'.$owned.'/mode', ['action' => 'take'])->assertSessionHasNoErrors();
        $this->post('/seller/dialogs/'.$waiting.'/mode', ['action' => 'take'])->assertSessionHasErrors('action');
        $this->assertDatabaseHas('conversations', ['id' => $waiting, 'assigned_user_id' => null]);
        $this->post('/seller/dialogs/'.$foreign.'/mode', ['action' => 'take'])->assertNotFound();
        DB::table('conversations')->where('id', $owned)->update(['stage' => 'done']);
        $this->post('/seller/dialogs/'.$waiting.'/mode', ['action' => 'take'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $waiting, 'assigned_user_id' => $owner->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/dialogs/'.$waiting.'/mode', ['action' => 'take'])->assertForbidden();
    }
}
