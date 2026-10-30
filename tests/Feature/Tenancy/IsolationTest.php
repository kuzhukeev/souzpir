<?php

namespace Tests\Feature\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\Fixtures\TenantRecord;

final class IsolationTest extends TenancyTestCase
{
    public function test_context_and_scope_fail_closed_and_restore_after_nested_exception(): void
    {
        [, $org,$a,$b] = $this->cabinet();
        [, $other,$foreign] = $this->cabinet(false);
        $record = $this->within($org, $a, fn () => TenantRecord::create(['name' => 'A']));
        $this->assertNull(TenantRecord::find($record->id));
        $this->assertNull(Brand::find($a->id));
        $this->within($org, null, function () use ($record): void {
            $this->assertNull(TenantRecord::find($record->id));
        });
        $this->within($org, $a, function () use ($org, $other, $foreign, $record, $b): void {
            $this->assertNotNull(TenantRecord::find($record->id));
            $this->assertNull(Brand::find($b->id));
            try {
                $this->within($other, $foreign, fn () => throw new \RuntimeException('fixture failure'));
            } catch (\RuntimeException) {
            }
            $this->assertSame($org->id, app(TenantContext::class)->organizationId);
            $this->assertNotNull(TenantRecord::find($record->id));
        });
        $this->assertNull(app(TenantContext::class)->organizationId);
        $this->expectException(\LogicException::class);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, $foreign->id), fn () => null);
    }

    /** Integration prerequisite: ResolveTenant must precede SubstituteBindings in root bootstrap. */
    public function test_implicit_binding_resolves_only_the_active_brand_after_tenant_middleware(): void
    {
        [$owner, $org, $a, $b] = $this->cabinet();
        [, $other, $foreign] = $this->cabinet(false);
        $activeRecord = $this->within($org, $a, fn () => TenantRecord::create(['name' => 'Active']));
        $siblingRecord = $this->within($org, $b, fn () => TenantRecord::create(['name' => 'Sibling']));
        $foreignRecord = $this->within($other, $foreign, fn () => TenantRecord::create(['name' => 'Foreign']));
        $this->actingAs($owner)->withSession(['brand_id' => $a->id]);
        $this->getJson('/tenancy-test/implicit/'.$activeRecord->id)->assertOk();
        $this->getJson('/tenancy-test/implicit/'.$siblingRecord->id)->assertNotFound();
        $this->getJson('/tenancy-test/implicit/'.$foreignRecord->id)->assertNotFound();
        $this->assertNull(app(TenantContext::class)->organizationId);
    }

    public function test_same_org_and_foreign_org_records_and_brands_return_404(): void
    {
        [$owner,$org,$a,$b] = $this->cabinet();
        [, $other,$foreign] = $this->cabinet(false);
        $aRecord = $this->within($org, $a, fn () => TenantRecord::create(['name' => 'A']));
        $bRecord = $this->within($org, $b, fn () => TenantRecord::create(['name' => 'B']));
        $foreignRecord = $this->within($other, $foreign, fn () => TenantRecord::create(['name' => 'Other']));
        $this->actingAs($owner)->withSession(['brand_id' => $a->id]);
        $this->getJson('/tenancy-test/records/'.$aRecord->id)->assertOk();
        $this->getJson('/tenancy-test/records/'.$bRecord->id)->assertNotFound();
        $this->getJson('/tenancy-test/records/'.$foreignRecord->id)->assertNotFound();
        $this->getJson('/tenancy-test/brands/'.$b->id)->assertNotFound();
        $this->getJson('/tenancy-test/brands/'.$foreign->id)->assertNotFound();
        $this->assertNull(app(TenantContext::class)->organizationId);
    }

    public function test_switch_updates_session_and_lazy_shared_props_and_ignores_request_organization(): void
    {
        [$owner,$org,$a,$b] = $this->cabinet();
        [, $other,$foreign] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $a->id]);
        $this->post('/brand/switch', ['brand_id' => $b->id, 'organization_id' => $other->id])->assertRedirect('/');
        $this->assertSame($b->id, session('brand_id'));
        $this->get('/tenancy-test/page?organization_id='.$other->id)->assertInertia(fn (Assert $p) => $p
            ->component('Tenancy/Test', false)->where('organization.id', $org->id)->where('brand.id', $b->id)
            ->where('brand.theme', 'pyro')->has('brands', 2)->where('can', []));
        $this->postJson('/brand/switch', ['brand_id' => $foreign->id])->assertNotFound();
        $this->postJson('/brand/switch', ['brand_id' => 'bad'])->assertUnprocessable();
        $this->withHeader('X-Brand-Id', $a->id)->getJson('/tenancy-test/page')->assertNotFound();
    }

    public function test_viewer_can_switch_only_between_authorized_brands_and_has_no_actions(): void
    {
        [, $org,$a,$b] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$a->id, $b->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $a->id]);
        $this->post('/brand/switch', ['brand_id' => $b->id])->assertRedirect('/');
        $this->get('/tenancy-test/page')->assertInertia(fn (Assert $p) => $p->where('can', [])->where('brand.id', $b->id));
        $this->postJson('/tenancy-test/records', [])->assertForbidden();
        $this->assertSame(0, DB::table('tenancy_test_records')->count());
    }

    public function test_empty_grants_are_wildcard_only_for_owner_admin_and_disabled_membership_is_denied(): void
    {
        [, $org,$a] = $this->cabinet();
        foreach (['member', 'viewer'] as $role) {
            [$user] = $this->member($org, $role);
            $this->actingAs($user)->getJson('/tenancy-test/page')->assertForbidden();
        }
        [$admin,$membership] = $this->member($org, 'admin');
        $this->actingAs($admin)->get('/tenancy-test/page')->assertOk();
        $this->within($org, null, fn () => $membership->update(['status' => 'disabled']));
        $this->getJson('/tenancy-test/page')->assertForbidden();
        [$limited] = $this->member($org, 'viewer', [$a->id]);
        $this->actingAs($limited)->get('/tenancy-test/page')->assertInertia(fn (Assert $p) => $p->has('brands', 1));
    }

    public function test_cross_organization_grants_and_manager_flag_on_viewer_are_rejected(): void
    {
        [, $org] = $this->cabinet();
        [, ,$foreign] = $this->cabinet(false);
        try {
            $this->member($org, 'member', [$foreign->id]);
            $this->fail('Foreign brand grant accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('brand_ids', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $this->member($org, 'viewer', [], ['sales_manager' => true]);
    }

    public function test_model_rejects_cross_context_create_update_and_delete(): void
    {
        [, $org,$a,$b] = $this->cabinet();
        $record = $this->within($org, $a, fn () => TenantRecord::create(['name' => 'A']));
        foreach ([
            fn () => TenantRecord::create(['name' => 'Wrong', 'brand_id' => $a->id]),
            fn () => $record->update(['name' => 'Wrong']),
            fn () => $record->delete(),
        ] as $mutation) {
            try {
                $this->within($org, $b, $mutation);
                $this->fail('Cross-brand mutation allowed.');
            } catch (\LogicException) {
                $this->assertSame('A', DB::table('tenancy_test_records')->where('id', $record->id)->value('name'));
            }
        }
    }

    public function test_database_rejects_mismatched_brand_organization_even_without_eloquent(): void
    {
        [, $org] = $this->cabinet();
        [, ,$foreign] = $this->cabinet(false);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('tenancy_test_records')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $foreign->id, 'name' => 'Invalid',
        ]));
    }

    public function test_designated_owner_cannot_be_disabled_or_deleted_and_org_has_no_delete_action(): void
    {
        [, $org,,, $owner] = $this->cabinet();
        foreach ([fn () => $owner->update(['status' => 'disabled']), fn () => $owner->delete(), fn () => $org->delete()] as $mutation) {
            try {
                $this->within($org, null, $mutation);
                $this->fail('Designated owner or organization removed.');
            } catch (\LogicException) {
                $this->assertSame(1, Organization::query()->count());
            }
        }
        $this->assertSame('active', DB::table('memberships')->where('id', $owner->id)->value('status'));
    }

    public function test_job_context_rejects_mismatched_actor_membership_and_clears_scoped_instance(): void
    {
        [$owner,$org,$a,, $membership] = $this->cabinet();
        [$other] = $this->member($org, 'viewer', [$a->id]);
        try {
            app(TenantContextRunner::class)->run(new TenantContext($org->id, $a->id, $other->id, $membership->id, 'job'), fn () => null);
            $this->fail('Mismatched membership accepted.');
        } catch (\LogicException) {
            $this->assertNull(app(TenantContext::class)->organizationId);
        }
        app(TenantContextRunner::class)->run(new TenantContext($org->id, $a->id, $owner->id, $membership->id, 'job'), function (): void {
            $this->assertNotNull(app(TenantContext::class)->organizationId);
        });
        $before = app(TenantContext::class);
        $this->app->forgetScopedInstances();
        $this->assertNotSame($before, app(TenantContext::class));
        $this->assertNull(app(TenantContext::class)->organizationId);
    }

    public function test_duplicate_membership_and_invalid_manager_role_are_database_constraints(): void
    {
        [, $org,,,$membership] = $this->cabinet();
        foreach ([
            fn () => DB::table('memberships')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $org->id, 'user_id' => $membership->user_id,
                'role' => 'viewer', 'brand_ids' => '[]', 'settings' => '{}',
            ]),
            fn () => DB::table('memberships')->where('id', $membership->id)->update(['settings' => '{"sales_manager":true}']),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Invalid row bypassed database constraints.');
            } catch (QueryException) {
                $this->assertSame(1, Membership::withoutGlobalScopes()->count());
            }
        }
    }

    public function test_archived_brand_and_disabled_organization_cannot_be_selected(): void
    {
        [$owner,$org,$a,$b] = $this->cabinet();
        $this->within($org, $b, fn () => $b->update(['status' => 'archived']));
        $this->actingAs($owner)->withSession(['brand_id' => $a->id])->postJson('/brand/switch', ['brand_id' => $b->id])->assertNotFound();
        $org->update(['status' => 'disabled']);
        $this->getJson('/tenancy-test/page')->assertForbidden();
    }

    public function test_verified_auth_and_csrf_are_real_route_boundaries(): void
    {
        $this->getJson('/tenancy-test/page')->assertUnauthorized();
        $unverified = $this->user(false);
        $this->actingAs($unverified)->getJson('/tenancy-test/page')->assertForbidden();
        [$owner,, $a] = $this->cabinet();
        $this->actingAs($owner);
        $this->app->instance('env','local'); // Disable the framework's testing-only CSRF bypass.
        $this->postJson('/brand/switch',['brand_id' => $a->id])->assertStatus(419);
    }
}
