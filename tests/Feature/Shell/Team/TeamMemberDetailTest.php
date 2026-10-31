<?php

namespace Tests\Feature\Shell\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamMemberDetailTest extends TenancyTestCase
{
    public function test_owner_sees_real_member_details_but_not_foreign_or_nonexistent_members(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$target, $membership] = $this->member($org, 'member', [$first->id, $second->id], ['sales_manager' => true]);
        $target->forceFill(['name' => 'Тестовый менеджер', 'phone' => '+7 999 123-45-67'])->save();
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        [$foreign, $foreignOrg] = $this->cabinet(false);
        [, $foreignMembership] = $this->member($foreignOrg, 'viewer');
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $response = $this->get('/team/'.$membership->id);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Team/Show', false)
            ->where('member.id', $membership->id)->where('member.name', 'Тестовый менеджер')
            ->where('member.phone', '+7 999 123-45-67')->where('member.role_label', 'Менеджер')
            ->where('member.status', 'disabled')->has('member.brands', 2));
        $this->assertSame(['id', 'name', 'email', 'role', 'role_label', 'status', 'brands', 'phone'], array_keys($response->inertiaProps('member')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/team/'.$foreignMembership->id)->assertNotFound();
        $this->get('/team/'.$foreign->id)->assertNotFound();
        $this->get('/team/invalid')->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_viewer_can_only_see_brands_shared_with_the_target(): void
    {
        [, $org, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        [$target, $membership] = $this->member($org, 'member', [$first->id, $second->id]);
        $response = $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/team/'.$membership->id);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('member.email', $target->email)
            ->has('member.brands', 1)->where('member.brands.0.id', $first->id));
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($second->id, $content);
    }

    public function test_detail_requires_active_team_read_membership(): void
    {
        [, $org, $brand] = $this->cabinet();
        [, $target] = $this->member($org, 'viewer', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$admin, $adminMembership] = $this->member($org, 'admin', [$brand->id]);
        $this->get('/team/'.$target->id)->assertRedirect('/login');
        foreach ([$editor, $manager] as $user) {
            $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get('/team/'.$target->id)->assertForbidden();
        }
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->get('/team/'.$target->id)->assertOk();
        DB::table('memberships')->where('id', $adminMembership->id)->update(['status' => 'disabled']);
        $this->get('/team/'.$target->id)->assertForbidden();
    }
}
