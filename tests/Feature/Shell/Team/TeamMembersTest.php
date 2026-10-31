<?php

namespace Tests\Feature\Shell\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamMembersTest extends TenancyTestCase
{
    public function test_owner_sees_only_organization_members_with_real_roles_and_statuses(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$manager, $managerMembership] = $this->member($org, 'member', [$first->id, $second->id], ['sales_manager' => true]);
        [$disabled, $disabledMembership] = $this->member($org, 'viewer', [$first->id]);
        DB::table('memberships')->where('id', $disabledMembership->id)->update(['status' => 'disabled']);
        [$foreign, , $foreignBrand] = $this->cabinet(false);
        $foreign->forceFill(['name' => 'Чужой участник', 'email' => 'foreign-team@example.test'])->save();

        $response = $this->actingAs($owner)->withSession(['brand_id' => $first->id])->get('/team');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Team/Index', false)
            ->has('members.data', 3)->where('members.total', 3)->where('canViewRoles', true));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $rows = collect($response->inertiaProps('members.data'))->keyBy('id');
        $this->assertSame('Менеджер', $rows[$managerMembership->id]['role_label']);
        $this->assertSame('manager', $rows[$managerMembership->id]['role']);
        $this->assertSame([$first->id, $second->id], collect($rows[$managerMembership->id]['brands'])->pluck('id')->all());
        $this->assertSame('disabled', $rows[$disabledMembership->id]['status']);
        $this->assertSame($disabled->email, $rows[$disabledMembership->id]['email']);
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($foreign->email, $content);
        $this->assertStringNotContainsString($foreignBrand->id, $content);
        foreach ($rows as $row) {
            $this->assertSame(['id', 'name', 'email', 'role', 'role_label', 'status', 'brands'], array_keys($row));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_restricted_admin_sees_only_brand_names_they_can_access(): void
    {
        [, $org, $first, $second] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$first->id]);
        [$other, $otherMembership] = $this->member($org, 'member', [$second->id]);
        $response = $this->actingAs($admin)->withSession(['brand_id' => $first->id])->get('/team');
        $response->assertOk();
        $rows = collect($response->inertiaProps('members.data'))->keyBy('id');
        $this->assertSame($other->email, $rows[$otherMembership->id]['email']);
        $this->assertSame([], $rows[$otherMembership->id]['brands']);
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($second->id, $content);
        $this->assertStringNotContainsString('"name":"B"', $content);
    }

    public function test_viewer_can_read_but_editor_manager_foreign_and_disabled_actor_cannot(): void
    {
        $this->get('/team')->assertRedirect('/login');
        [, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$admin, $adminMembership] = $this->member($org, 'admin', [$brand->id]);
        [$foreign, , $foreignBrand] = $this->cabinet(false);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewRoles', false));
        foreach ([$editor, $manager] as $user) {
            $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get('/team')->assertForbidden();
        }
        $this->actingAs($foreign)->withSession(['brand_id' => $foreignBrand->id])->get('/team')->assertForbidden();
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->get('/team')->assertOk();
        DB::table('memberships')->where('id', $adminMembership->id)->update(['status' => 'disabled']);
        $this->get('/team')->assertForbidden();
    }

    public function test_pagination_uses_real_counts_and_rejects_invalid_pages(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        for ($i = 0; $i < 26; $i++) {
            $this->member($org, 'member', [$brand->id]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('members.data', 25)->where('members.total', 27)->where('members.current_page', 1)->where('members.last_page', 2));
        $this->get('/team?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('members.data', 2)->where('members.total', 27)->where('members.current_page', 2));
        $this->get('/team?page=3')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('members.data', 0)->where('members.total', 27));
        $this->get('/team?page=0')->assertNotFound();
        $this->get('/team?page=bad')->assertNotFound();
    }
}
