<?php

namespace Tests\Feature\Shell\Permissions;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\User;
use App\Shell\Permissions\PermissionSharedProps;
use App\Shell\Permissions\Policies\BrandPolicy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PermissionsTest extends TenancyTestCase
{
    public function test_gate_matrix_for_five_roles_and_twelve_actions(): void
    {
        [$owner, $org, $brand, , $ownerMembership] = $this->cabinet();
        [$admin, $adminMembership] = $this->member($org, 'admin');
        [$editor, $editorMembership] = $this->member($org, 'member', [$brand->id]);
        [$manager, $managerMembership] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer, $viewerMembership] = $this->member($org, 'viewer', [$brand->id]);
        $actors = [[$owner, $ownerMembership], [$admin, $adminMembership], [$editor, $editorMembership], [$manager, $managerMembership], [$viewer, $viewerMembership]];
        $expected = [
            'team.read' => [1, 1, 0, 0, 1], 'team.write' => [1, 1, 0, 0, 0],
            'journal.read' => [1, 1, 1, 0, 0], 'journal.notifications.read' => [1, 1, 0, 0, 0], 'journal.retry' => [1, 1, 0, 0, 0],
            'org.billing' => [1, 0, 0, 0, 0], 'billing.read' => [1, 1, 0, 0, 1],
            'seller.dialogs.read' => [1, 1, 1, 1, 1], 'seller.dialogs.write' => [1, 1, 1, 1, 0],
            'seller.knowledge.read' => [1, 1, 1, 0, 1], 'seller.knowledge.write' => [1, 1, 1, 0, 0],
            'vision.report.read' => [1, 1, 1, 1, 1], 'vision.measure.run' => [1, 1, 1, 0, 0],
            'integrations.write' => [1, 1, 0, 0, 0], 'brand.write' => [1, 1, 0, 0, 0],
            'organization.manage' => [1, 1, 0, 0, 0], 'team.manage' => [1, 1, 0, 0, 0],
            'billing.manage' => [1, 0, 0, 0, 0], 'seller.dialogues.operate' => [1, 1, 1, 1, 0],
            'vision.quiz.write' => [1, 1, 1, 0, 0], 'vision.actions.write' => [1, 1, 1, 0, 0], 'reports.read' => [1, 1, 1, 1, 1],
            'seller.settings.write' => [1, 1, 0, 0, 0], 'vision.settings.write' => [1, 1, 0, 0, 0],
        ];
        foreach ($actors as $index => [$user, $membership]) {
            $this->withActor($org->id, $brand->id, $user, $membership, function () use ($user, $expected, $index): void {
                foreach ($expected as $ability => $roles) {
                    $this->assertSame((bool) $roles[$index], Gate::forUser($user)->allows($ability), "{$ability}: role {$index}");
                }
                $this->assertFalse(Gate::forUser($user)->allows('does.not.exist'));
            });
        }
    }

    public function test_policy_rejects_foreign_and_sibling_brand_and_owner_mutation(): void
    {
        [$owner, $org, $brand, $sibling, $membership] = $this->cabinet();
        [, $foreignOrg, $foreignBrand, , $foreignMembership] = $this->cabinet(false);
        [$other, $otherMembership] = $this->member($org, 'member', [$brand->id]);
        $this->withActor($org->id, $brand->id, $owner, $membership, function () use ($owner, $brand, $sibling, $foreignBrand, $membership, $otherMembership, $foreignMembership): void {
            $this->assertTrue(Gate::forUser($owner)->allows('view', $brand));
            $this->assertTrue(app(BrandPolicy::class)->viewAny($owner));
            $this->assertTrue(Gate::forUser($owner)->allows('viewAny', Brand::class));
            $this->assertTrue(Gate::forUser($owner)->allows('create', Membership::class));
            $this->assertTrue(Gate::forUser($owner)->allows('update', $brand));
            $this->assertFalse(Gate::forUser($owner)->allows('view', $sibling));
            $this->assertFalse(Gate::forUser($owner)->allows('view', $foreignBrand));
            $this->assertFalse(Gate::forUser($owner)->allows('update', $membership));
            $this->assertTrue(Gate::forUser($owner)->allows('update', $otherMembership));
            $this->assertFalse(Gate::forUser($owner)->allows('view', $foreignMembership));
            $partial = Membership::withoutGlobalScopes()->select('id')->findOrFail($otherMembership->id);
            $this->assertFalse(Gate::forUser($owner)->allows('view', $partial));
        });
    }

    public function test_revocation_and_actor_or_context_mismatch_fail_closed(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$user, $membership] = $this->member($org, 'member', [$brand->id]);
        $other = $this->user();
        $this->assertFalse(Gate::forUser($user)->allows('seller.dialogs.read'));
        $this->withActor($org->id, $brand->id, $user, $membership, function () use ($user, $other, $membership): void {
            $this->assertTrue(Gate::forUser($user)->allows('seller.dialogs.read'));
            $this->assertTrue(Gate::forUser($user)->allows('vision.actions.write'));
            $this->assertFalse(Gate::forUser($other)->allows('seller.dialogs.read'));
            DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
            $this->assertFalse(Gate::forUser($user)->allows('seller.dialogs.read'));
            $this->assertFalse(Gate::forUser($user)->allows('vision.actions.write'));
        });
    }

    public function test_inertia_receives_real_role_specific_permissions_and_anonymous_props_are_empty(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$user] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/permissions-test', fn () => Inertia::render('Testing/Permissions'));
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->get('/permissions-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Testing/Permissions', false)
            ->where('can', fn ($can) => $can['seller.dialogs.write'] === true && $can['seller.knowledge.read'] === false && $can['team.write'] === false));
        $request = Request::create('/');
        $request->setUserResolver(fn () => null);
        $this->assertSame([], (new PermissionSharedProps)->share($request)['can']());
    }

    public function test_viewer_may_change_own_consents_but_not_other_unsafe_routes(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->patch('/test-own-consent', fn () => response()->noContent())->name('data.consents');
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->patch('/test-other-change', fn () => response()->noContent())->name('data.other');
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);

        $this->patch('/test-own-consent', ['marketing_opt_in' => true])->assertNoContent();
        $this->patch('/test-other-change', ['marketing_opt_in' => true])->assertForbidden();
    }

    public function test_archived_brand_or_disabled_organization_revokes_existing_context(): void
    {
        [, $org, $brand, , $ownerMembership] = $this->cabinet();
        $owner = $ownerMembership->user;
        $this->withActor($org->id, $brand->id, $owner, $ownerMembership, function () use ($owner, $brand, $org): void {
            $this->assertTrue(Gate::forUser($owner)->allows('seller.dialogs.read'));
            DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
            $this->assertFalse(Gate::forUser($owner)->allows('seller.dialogs.read'));
            DB::table('brands')->where('id', $brand->id)->update(['status' => 'active']);
            DB::table('organizations')->where('id', $org->id)->update(['status' => 'disabled']);
            $this->assertFalse(Gate::forUser($owner)->allows('seller.dialogs.read'));
        });
    }

    private function withActor(string $organizationId, string $brandId, User $user, Membership $membership, \Closure $callback): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($organizationId, $brandId, $user->id, $membership->id), $callback);
    }
}
