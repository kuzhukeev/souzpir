<?php

namespace Tests\Feature\Seller\Tasks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TransferTaskTest extends TenancyTestCase
{
    public function test_transfer_preserves_other_tasks_and_records_audit(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$recipient] = $this->member($org, 'member', [$brand->id]);
        $this->enableSeller($org->id, $brand->id);
        $active = $this->task($org->id, $brand->id, $owner->id, 'new');
        $done = $this->task($org->id, $brand->id, $owner->id, 'done');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->postJson('/seller/tasks/'.$active.'/transfer', ['assigned_user_id' => $recipient->id])->assertOk();
        $this->assertDatabaseHas('seller_tasks', ['id' => $active, 'assigned_user_id' => $recipient->id]);
        $this->assertDatabaseHas('seller_tasks', ['id' => $done, 'assigned_user_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.task.transferred', 'subject_id' => $active, 'brand_id' => $brand->id]);
    }

    public function test_permissions_brand_and_validation_fail_closed(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$other] = $this->member($org, 'member', [$otherBrand->id]);
        [$recipient] = $this->member($org, 'member', [$brand->id]);
        $this->enableSeller($org->id, $brand->id);
        $active = $this->task($org->id, $brand->id, $owner->id, 'new');
        $closed = $this->task($org->id, $brand->id, $owner->id, 'cancelled');
        $foreign = $this->task($org->id, $otherBrand->id, $owner->id, 'new');
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson('/seller/tasks/'.$active.'/transfer', ['assigned_user_id' => $recipient->id])->assertForbidden();
        $this->flushSession()->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/tasks/'.$active.'/transfer', ['assigned_user_id' => $other->id])->assertUnprocessable();
        $this->postJson('/seller/tasks/'.$active.'/transfer', ['assigned_user_id' => $viewer->id])->assertUnprocessable();
        $this->postJson('/seller/tasks/'.$active.'/transfer', ['assigned_user_id' => 'invalid'])->assertUnprocessable();
        $this->postJson('/seller/tasks/'.$closed.'/transfer', ['assigned_user_id' => $recipient->id])->assertUnprocessable();
        $this->postJson('/seller/tasks/'.$foreign.'/transfer', ['assigned_user_id' => $recipient->id])->assertNotFound();
        $this->assertDatabaseHas('seller_tasks', ['id' => $active, 'assigned_user_id' => $owner->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'seller.task.transferred']);
    }

    private function enableSeller(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function task(string $organization, string $brand, string $user, string $status): string
    {
        $conversation = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $organization, 'brand_id' => $brand, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_tasks')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'conversation_id' => $conversation, 'type' => 'handoff', 'status' => $status, 'assigned_user_id' => $user, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
