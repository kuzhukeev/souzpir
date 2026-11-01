<?php

namespace Tests\Feature\Shell\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class InvitationPermissionsTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_invitation_and_role_matrix_capabilities_are_independent(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$owner, $organization, $brand, $other] = $this->cabinet();
        [$admin, $adminMembership] = $this->member($organization, 'admin', [$brand->id]);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $abilities = config('permissions.abilities');
        $abilities['team.write'] = ['admin'];
        $abilities['team.manage'] = ['owner'];
        config(['permissions.abilities' => $abilities]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewRoles', true)->where('canIssueInvitations', false));
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->get('/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewRoles', false)->where('canIssueInvitations', true));
        $this->get('/team', ['X-Brand-Id' => $other->id])->assertNotFound();
        $abilities['team.write'] = ['viewer'];
        config(['permissions.abilities' => $abilities]);
        $this->get('/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewRoles', false)->where('canIssueInvitations', false));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewRoles', false)->where('canIssueInvitations', false));
        DB::table('memberships')->where('id', $adminMembership->id)->update(['status' => 'disabled']);
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->get('/team')->assertForbidden();
        Http::assertNothingSent();
    }
}
