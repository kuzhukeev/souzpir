<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Team\RoleMatrix;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RoleMatrixTest extends TenancyTestCase
{
    public function test_role_matrix_page_has_the_same_authorization_boundary(): void
    {
        $this->get('/team/roles')->assertRedirect('/login');
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$owner, $admin] as $user) {
            $response = $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get('/team/roles');
            $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Team/Roles', false));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        foreach ([$editor, $manager, $viewer] as $user) {
            $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get('/team/roles')->assertForbidden();
        }
    }

    public function test_matrix_endpoint_requires_owner_or_admin_and_is_not_cached(): void
    {
        $this->getJson('/team/roles/matrix')->assertUnauthorized();
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);

        foreach ([$owner, $admin] as $user) {
            $response = $this->actingAs($user)->withSession(['brand_id' => $brand->id])->getJson('/team/roles/matrix');
            $response->assertOk()->assertJsonPath('roles.0.key', 'owner')->assertJsonPath('roles.3.key', 'manager')
                ->assertJsonPath('roles.3.membership_role', 'member')->assertJsonPath('roles.3.sales_manager', true)
                ->assertJsonPath('limitations.requires_active_membership_and_brand', true)
                ->assertJsonPath('limitations.model_policies_apply', true)
                ->assertJsonPath('limitations.only_role_based_abilities_shown', true)
                ->assertJsonPath('limitations.custom_roles_available', false)
                ->assertJsonPath('limitations.per_module_grants_available', false);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString($user->email, $response->getContent());
        }
        foreach ([$editor, $manager, $viewer] as $user) {
            $this->actingAs($user)->withSession(['brand_id' => $brand->id])->getJson('/team/roles/matrix')->assertForbidden();
        }
    }

    public function test_each_projected_ability_matches_the_real_gate_for_every_active_role(): void
    {
        [$owner, $org, $brand, , $ownerMembership] = $this->cabinet();
        [$admin, $adminMembership] = $this->member($org, 'admin', [$brand->id]);
        [$editor, $editorMembership] = $this->member($org, 'member', [$brand->id]);
        [$manager, $managerMembership] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer, $viewerMembership] = $this->member($org, 'viewer', [$brand->id]);
        $matrix = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->getJson('/team/roles/matrix')->assertOk()->json();

        $this->assertSame(['owner', 'admin', 'editor', 'manager', 'viewer'], array_column($matrix['roles'], 'key'));
        $this->assertSame(array_keys(config('permissions.abilities')), array_column($matrix['abilities'], 'ability'));
        foreach ([
            'owner' => [$owner, $ownerMembership], 'admin' => [$admin, $adminMembership],
            'editor' => [$editor, $editorMembership], 'manager' => [$manager, $managerMembership],
            'viewer' => [$viewer, $viewerMembership],
        ] as $role => [$user, $membership]) {
            $this->withActor($org->id, $brand->id, $user, $membership, function () use ($user, $role, $matrix): void {
                foreach ($matrix['abilities'] as $row) {
                    $this->assertSame(Gate::forUser($user)->allows($row['ability']), $row['roles'][$role], "{$role}: {$row['ability']}");
                }
            });
        }
    }

    public function test_projection_tracks_config_but_fails_closed_for_bad_or_unregistered_abilities(): void
    {
        [$owner, $org, $brand, , $ownerMembership] = $this->cabinet();
        [$viewer, $viewerMembership] = $this->member($org, 'viewer', [$brand->id]);
        $abilities = config('permissions.abilities');
        $abilities['team.write'] = ['viewer'];
        $abilities['vision.quiz.write'] = 'owner';
        $abilities['unregistered.permission'] = ['owner'];
        config(['permissions.abilities' => $abilities]);

        $rows = collect(app(RoleMatrix::class)->project()['abilities'])->keyBy('ability');
        $this->assertTrue($rows['team.write']['roles']['viewer']);
        $this->assertFalse($rows['team.write']['roles']['owner']);
        $this->assertSame(['owner' => false, 'admin' => false, 'editor' => false, 'manager' => false, 'viewer' => false], $rows['vision.quiz.write']['roles']);
        $this->assertFalse($rows->has('unregistered.permission'));
        $this->withActor($org->id, $brand->id, $viewer, $viewerMembership, fn () => $this->assertTrue(Gate::forUser($viewer)->allows('team.write')));
        $this->withActor($org->id, $brand->id, $owner, $ownerMembership, fn () => $this->assertFalse(Gate::forUser($owner)->allows('vision.quiz.write')));
    }

    public function test_projection_is_not_an_authorization_decision(): void
    {
        [$owner, $org, $brand, , $ownerMembership] = $this->cabinet();
        $rows = collect(app(RoleMatrix::class)->project()['abilities'])->keyBy('ability');
        $this->assertTrue($rows['team.write']['roles']['owner']);
        $this->assertFalse(Gate::forUser($owner)->allows('team.write'));
        $this->withActor($org->id, $brand->id, $owner, $ownerMembership, function () use ($owner, $ownerMembership): void {
            $this->assertTrue(Gate::forUser($owner)->allows('team.write'));
            $this->assertFalse(Gate::forUser($owner)->allows('update', $ownerMembership));
        });
    }

    public function test_disabled_membership_and_other_organization_cannot_read_matrix(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$admin, $membership] = $this->member($org, 'admin', [$brand->id]);
        [, , $foreignBrand] = $this->cabinet(false);
        [$foreignUser] = $this->member($foreignBrand->organization, 'admin', [$foreignBrand->id]);
        $this->actingAs($foreignUser)->withSession(['brand_id' => $foreignBrand->id])->getJson('/team/roles/matrix')->assertForbidden();
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->getJson('/team/roles/matrix')->assertOk();
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        $this->getJson('/team/roles/matrix')->assertForbidden();
    }

    private function withActor(string $organizationId, string $brandId, User $user, Membership $membership, \Closure $callback): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($organizationId, $brandId, $user->id, $membership->id), $callback);
    }
}
