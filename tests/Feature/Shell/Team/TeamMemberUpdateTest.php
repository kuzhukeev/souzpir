<?php

namespace Tests\Feature\Shell\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamMemberUpdateTest extends TenancyTestCase
{
    public function test_owner_changes_role_and_access_and_can_disable(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [, $member] = $this->member($org, 'viewer', [$first->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->withHeader('X-Brand-Revision', $this->get('/team/'.$member->id)->inertiaProps('editAccess.revision'));
        $this->patch('/team/'.$member->id, ['role' => 'manager', 'brand_ids' => [$second->id], 'status' => 'active'])->assertRedirect();
        $member->refresh();
        $this->assertSame('member', $member->role);
        $this->assertTrue($member->settings['sales_manager']);
        $this->assertSame([$second->id], $member->brand_ids);
        $this->assertDatabaseHas('audit_logs', ['action' => 'team.member.updated', 'subject_id' => $member->id]);
        $this->patch('/team/'.$member->id, ['role' => 'manager', 'brand_ids' => [$second->id], 'status' => 'disabled'])->assertRedirect();
        $this->assertSame('disabled', $member->fresh()->status);
    }

    public function test_access_and_input_are_checked(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        [$admin] = $this->member($org, 'admin', [$first->id]);
        [, $member] = $this->member($org, 'member', [$first->id, $second->id]);
        $payload = ['role' => 'viewer', 'brand_ids' => [$first->id], 'status' => 'active'];
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->patch('/team/'.$member->id, $payload)->assertForbidden();
        $this->actingAs($admin)->withSession(['brand_id' => $first->id]);
        $this->withHeader('X-Brand-Revision', $this->get('/team')->inertiaProps('teamBrandRevision'));
        $this->patch('/team/'.$member->id, $payload)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->withHeader('X-Brand-Revision', $this->get('/team')->inertiaProps('teamBrandRevision'));
        $this->patch('/team/'.$member->id, [...$payload, 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->patch('/team/'.$member->id, [...$payload, 'brand_ids' => []])->assertSessionHasErrors('brand_ids');
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        [, $foreign] = $this->member($foreignOrg, 'viewer', [$foreignBrand->id]);
        $this->patch('/team/'.$foreign->id, $payload)->assertNotFound();
        $this->patch('/team/'.$member->id, [...$payload, 'brand_ids' => [$foreignBrand->id]])->assertSessionHasErrors('brand_ids');
    }

    public function test_active_vision_assignment_blocks_change_and_completed_does_not(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [, $member] = $this->member($org, 'member', [$brand->id]);
        $id = (string) Str::ulid();
        DB::table('actions')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Задача', 'responsible' => $member->id, 'status' => 'open']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->withHeader('X-Brand-Revision', $this->get('/team/'.$member->id)->inertiaProps('editAccess.revision'));
        $payload = ['role' => 'viewer', 'brand_ids' => [$brand->id], 'status' => 'active'];
        $this->patch('/team/'.$member->id, $payload)->assertSessionHasErrors(['member' => 'Сначала передайте задачи']);
        DB::table('actions')->where('id', $id)->update(['status' => 'done']);
        $this->patch('/team/'.$member->id, $payload)->assertSessionHasNoErrors();
    }

    public function test_active_seller_assignments_block_disable(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$user, $member] = $this->member($org, 'member', [$brand->id]);
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'assigned_user_id' => $user->id, 'stage' => 'open']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->withHeader('X-Brand-Revision', $this->get('/team/'.$member->id)->inertiaProps('editAccess.revision'));
        $payload = ['role' => 'editor', 'brand_ids' => [$brand->id], 'status' => 'disabled'];
        $this->patch('/team/'.$member->id, $payload)->assertSessionHasErrors(['member' => 'Сначала передайте задачи']);
        DB::table('conversations')->where('id', $conversation)->update(['stage' => 'lost', 'assigned_user_id' => null]);
        DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'type' => 'followup', 'assigned_user_id' => $user->id, 'status' => 'new']);
        $this->patch('/team/'.$member->id, $payload)->assertSessionHasErrors(['member' => 'Сначала передайте задачи']);
    }
}
