<?php

namespace Tests\Feature\Shell\Organizations;

use App\Models\Brand;
use App\Models\Membership;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\Http\OrganizationController;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OrganizationTest extends TenancyTestCase
{
    private function plan(string $organization, array $limits): void
    {
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'test-'.Str::lower(Str::random(10)), 'name' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($limits as $key => $value) {
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => $key, 'value' => $value, 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_owner_updates_company_without_touching_existing_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        self::assertSame(env('DB_TEST_DATABASE'), DB::connection()->getDatabaseName());
        $org->forceFill(['settings' => ['existing' => ['preserved' => true]]])->save();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->patch('/company', ['name' => 'Компания', 'legal_name' => 'ООО Компания', 'inn' => '1234567890', 'industry' => 'Опт', 'country' => 'Россия', 'region' => 'Москва', 'timezone' => 'Europe/Moscow'])->assertRedirect('/company')->assertSessionHasNoErrors();
        self::assertSame('Компания', $org->fresh()->name);
        self::assertTrue($org->fresh()->settings['existing']['preserved']);
        self::assertSame('ООО Компания', $org->fresh()->settings['profile']['legal_name']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'organization.updated']);
        $this->delete('/company')->assertStatus(405);
        self::assertSame(1, DB::table('organizations')->count());
    }

    public function test_transfer_is_atomic_and_preserves_target_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$target] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true, 'other' => 'kept']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/company/transfer', ['user_id' => $target->id, 'confirm_name' => 'wrong'])->assertSessionHasErrors('confirm_name');
        self::assertSame($owner->id, $org->fresh()->owner_user_id);
        $this->post('/company/transfer', ['user_id' => $target->id, 'confirm_name' => $org->name])->assertSessionHasNoErrors();
        self::assertSame($target->id, $org->fresh()->owner_user_id);
        $old = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $owner->id)->sole();
        $new = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $target->id)->sole();
        self::assertSame('admin', $old->role);
        self::assertSame('owner', $new->role);
        self::assertSame([], $new->brand_ids);
        self::assertSame('kept', $new->settings['other']);
        self::assertFalse($new->settings['sales_manager']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'organization.owner.transferred']);
    }

    public function test_plan_limits_brand_creation_and_zero_modules_without_inventing_defaults(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->plan($org->id, ['brands.max' => '2', 'modules.seller' => '0']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/brands', ['name' => 'Третий', 'theme' => 'neutral'])->assertSessionHasErrors('brands.max');
        self::assertSame(2, DB::table('brands')->where('organization_id', $org->id)->count());
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasErrors('enabled');
        self::assertSame(0, DB::table('brand_modules')->count());
        $this->put('/brands/'.$brand->id.'/modules/vision', ['enabled' => true])->assertSessionHasNoErrors();
        $ctx = new TenantContext($org->id, $brand->id);
        self::assertTrue(app(ModuleEntitlements::class)->enabled($ctx, 'vision'));
        self::assertFalse(app(ModuleEntitlements::class)->enabled($ctx, 'seller'));
    }

    public function test_effective_module_gates_real_server_routes_and_plan_change(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/seller/feature-test', fn () => 'ok');
        Route::middleware(['web', 'auth', 'verified', 'tenant', 'module.enabled:vision'])->get('/module-alias-test', fn () => 'ok');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/feature-test')->assertNotFound();
        $this->get('/module-alias-test')->assertNotFound();
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasNoErrors();
        $this->put('/brands/'.$brand->id.'/modules/vision', ['enabled' => true])->assertSessionHasNoErrors();
        $this->get('/seller/feature-test')->assertOk();
        $this->get('/module-alias-test')->assertOk();
        $this->plan($org->id, ['modules.seller' => '0']);
        $this->get('/seller/feature-test')->assertNotFound();
        $this->get('/module-alias-test')->assertOk();
    }

    public function test_cross_tenant_brand_and_logo_are_not_revealed(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [, , $foreign] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/brands/'.$foreign->id.'/modules')->assertNotFound();
        $this->get('/brands/'.$foreign->id.'/logo')->assertNotFound();
        $this->put('/brands/'.$foreign->id.'/modules/seller', ['enabled' => true])->assertNotFound();
    }

    public function test_private_onboarding_logo_is_served_only_through_authenticated_brand_route(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $path = 'organizations/'.$org->id.'/onboarding/logos/'.str_repeat('a', 32).'.png';
        Storage::disk('local')->put($path, 'private-fixture');
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['logo_path' => $path], JSON_THROW_ON_ERROR)]);
        $this->get('/brands/'.$brand->id.'/logo')->assertRedirect('/login');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/brands/'.$brand->id.'/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('private-fixture');
    }

    public function test_archiving_last_brand_is_rejected_and_existing_settings_are_not_destroyed(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->patch('/brands/'.$other->id.'/archive', ['confirm_name' => $other->name])->assertSessionHasNoErrors();
        self::assertSame('archived', DB::table('brands')->where('id', $other->id)->value('status'));
        $this->patch('/brands/'.$brand->id.'/archive', ['confirm_name' => $brand->name])->assertSessionHasErrors('brand');
        self::assertSame('active', DB::table('brands')->where('id', $brand->id)->value('status'));
    }

    public function test_completed_onboarding_reads_effective_brand_modules_without_selecting_brand_context(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('onboarding_progress')->insert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'draft' => '{}', 'step' => 4, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->get('/start')->assertInertia(fn (Assert $page) => $page->where('modules.seller', true)->where('modules.vision', false));
    }

    public function test_owner_can_open_company_brand_and_modules_screens(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/company')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Organizations/Company'));
        $this->get('/brands')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Organizations/Brands')->has('items', 2));
        $this->get('/brands/'.$brand->id.'/modules')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Organizations/Modules')->where('items.seller.enabled', false)->where('items.vision.enabled', false));
    }

    public function test_editor_cannot_change_company_or_create_brand(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->get('/company')->assertForbidden();
        $this->post('/brands', ['name' => 'Another', 'theme' => 'neutral'])->assertForbidden();
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertForbidden();
    }

    public function test_administrator_cannot_transfer_owner(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin');
        [$target] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->post('/company/transfer', ['user_id' => $target->id, 'confirm_name' => $org->name])->assertForbidden();
    }

    public function test_same_organization_restricted_member_cannot_read_other_brand_logo(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$member] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->get('/brands/'.$other->id.'/logo')->assertNotFound();
    }

    public function test_automatic_web_and_explicit_api_guard_are_fail_closed_and_jobs_can_recheck(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/seller/e104b-test', fn () => 'web-ok');
        Route::middleware(['api', 'api.ingress', 'auth:sanctum', 'tenant.token', 'throttle:api', 'token.ability:read', 'module.enabled:seller'])
            ->get('/api/v1/seller/e104b-test', function () {
                $context = app(TenantContext::class);

                return response()->json(['organization_id' => $context->organizationId, 'brand_id' => $context->brandId]);
            });
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $token = static function ($organization, Brand $tokenBrand, array $abilities): string {
            $secret = Str::random(64);
            $id = DB::table('personal_access_tokens')->insertGetId([
                'tokenable_type' => Brand::class, 'tokenable_id' => $tokenBrand->id,
                'organization_id' => $organization->id, 'brand_id' => $tokenBrand->id,
                'name' => 'synthetic', 'token' => hash('sha256', $secret), 'token_last_four' => substr($secret, -4),
                'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id.'|'.$secret;
        };
        $read = $token($org, $brand, ['read']);
        $other = $token($org, $otherBrand, ['read']);
        $foreign = $token($foreignOrg, $foreignBrand, ['read']);
        $wrongAbility = $token($org, $brand, ['leads']);
        $this->getJson('/api/v1/seller/e104b-test')->assertUnauthorized();
        $this->get('/seller/e104b-test')->assertRedirect('/login');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        // A web session is not a bearer token and may not reach the module guard.
        $this->getJson('/api/v1/seller/e104b-test')->assertUnauthorized();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/e104b-test')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/seller/e104b-test')->assertNotFound();
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        self::assertFalse(app(ModuleEntitlements::class)->enabled($context, 'seller'));
        $this->flushHeaders();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasNoErrors();
        self::assertTrue(app(ModuleEntitlements::class)->enabled($context, 'seller'));
        $this->get('/seller/e104b-test')->assertOk()->assertSee('web-ok');
        $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/seller/e104b-test')
            ->assertOk()->assertExactJson(['organization_id' => $org->id, 'brand_id' => $brand->id]);
        $this->withHeader('X-Brand-Id', $otherBrand->id)->getJson('/api/v1/seller/e104b-test')->assertNotFound();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$other)->getJson('/api/v1/seller/e104b-test')->assertNotFound();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$foreign)->getJson('/api/v1/seller/e104b-test')->assertNotFound();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $foreignOrg->id, 'brand_id' => $foreignBrand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/seller/e104b-test')->assertOk()
            ->assertExactJson(['organization_id' => $foreignOrg->id, 'brand_id' => $foreignBrand->id]);
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$wrongAbility)->getJson('/api/v1/seller/e104b-test')->assertForbidden();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer 999|'.str_repeat('X', 64))->getJson('/api/v1/seller/e104b-test')->assertUnauthorized();
        $this->flushHeaders();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => false])->assertSessionHasNoErrors();
        self::assertFalse(app(ModuleEntitlements::class)->enabled($context, 'seller'));
        $this->get('/seller/e104b-test')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/seller/e104b-test')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_validation_errors_never_redirect_to_previous_logo_asset(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->from('/brands/'.$brand->id.'/logo')->post('/brands', ['name' => '', 'theme' => 'neutral'])->assertRedirect('/brands')->assertSessionHasErrors('name');
    }

    public function test_failed_transfer_audit_rolls_back_both_roles_and_owner(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$target] = $this->member($org, 'member', [$brand->id]);
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        try {
            $this->post('/company/transfer', ['user_id' => $target->id, 'confirm_name' => $org->name]);
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('audit unavailable', $e->getMessage());
        }
        self::assertSame($owner->id, $org->fresh()->owner_user_id);
        self::assertSame('owner', Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $owner->id)->value('role'));
        self::assertSame('member', Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $target->id)->value('role'));
    }

    public function test_module_page_keeps_selected_brand_separate_from_target(): void
    {
        [$owner, , $current, $target] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $current->id])->get('/brands/'.$target->id.'/modules')->assertInertia(fn (Assert $page) => $page->where('brand.id', $current->id)->where('targetBrand.id', $target->id));
    }

    public function test_module_page_exposes_real_quotas_and_unavailable_usage_without_fabricated_zero(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->plan($org->id, ['vision.runs_per_week' => '3']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/brands/'.$brand->id.'/modules')->assertInertia(fn (Assert $page) => $page->where('items.vision.quota.configured', true)->where('items.vision.quota.value', '3.0000')->where('items.vision.usage.status', 'unavailable')->where('items.vision.usage.value', null)->where('items.seller.quota.configured', false));
    }

    public function test_logo_rejects_a_path_outside_its_organization(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $path = 'organizations/01J00000000000000000000009/onboarding/logos/'.str_repeat('a', 32).'.png';
        Storage::disk('local')->put($path, 'other-tenant');
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['logo_path' => $path], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/brands/'.$brand->id.'/logo')->assertNotFound();
    }

    public function test_shared_module_state_matches_real_switch_and_plan(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/company')->assertInertia(fn (Assert $page) => $page->where('modules.seller', false));
        $this->put('/brands/'.$brand->id.'/modules/seller', ['enabled' => true])->assertSessionHasNoErrors();
        $this->get('/company')->assertInertia(fn (Assert $page) => $page->where('modules.seller', true));
        $this->plan($org->id, ['modules.seller' => '0']);
        $this->get('/company')->assertInertia(fn (Assert $page) => $page->where('modules.seller', false));
    }

    public function test_restricted_admin_gets_access_to_created_brand_atomically(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$admin, $membership] = $this->member($org, 'admin', [$brand->id]);
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->post('/brands', ['name' => 'Restricted admin brand', 'theme' => 'neutral'])->assertSessionHasNoErrors();
        $newId = DB::table('brands')->where('organization_id', $org->id)->where('name', 'Restricted admin brand')->value('id');
        self::assertNotNull($newId);
        self::assertSame([$brand->id, $newId], $membership->fresh()->brand_ids);
        self::assertFalse($membership->fresh()->permitsBrand($other->id));
        $this->get('/brands/'.$newId.'/modules')->assertOk();
        $this->get('/brands')->assertInertia(fn (Assert $page) => $page->has('items', 2));
    }

    public function test_unrestricted_admin_remains_unrestricted_when_creating_brand(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$admin, $membership] = $this->member($org, 'admin');
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->post('/brands', ['name' => 'Unrestricted admin brand', 'theme' => 'neutral'])->assertSessionHasNoErrors();
        $newId = DB::table('brands')->where('organization_id', $org->id)->where('name', 'Unrestricted admin brand')->value('id');
        self::assertNotNull($newId);
        self::assertSame([], $membership->fresh()->brand_ids);
        self::assertTrue($membership->fresh()->permitsBrand($newId));
        self::assertTrue($membership->fresh()->permitsBrand($other->id));
        $this->get('/brands/'.$newId.'/modules')->assertOk();
    }

    public function test_failed_brand_audit_rolls_back_new_brand_and_creator_grant(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$admin, $membership] = $this->member($org, 'admin', [$brand->id]);
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        try {
            $this->post('/brands', ['name' => 'Rolled back', 'theme' => 'neutral']);
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseMissing('brands', ['organization_id' => $org->id, 'name' => 'Rolled back']);
        self::assertSame([$brand->id], $membership->fresh()->brand_ids);
    }

    public function test_transfer_rechecks_locked_actor_access_and_selected_brand_status(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        [$target] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($owner);
        Gate::before(fn () => true);
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id, 'session');
        $request = Request::create('/company/transfer', 'POST', ['user_id' => $target->id, 'confirm_name' => $org->name]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $owner);
        $transfer = fn (\Closure $change) => app(TenantContextRunner::class)->run($ctx, function () use ($change, $request) {
            $change();

            return app(OrganizationController::class)->transfer($request);
        });
        try {
            $transfer(fn () => DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => json_encode([$other->id], JSON_THROW_ON_ERROR)]));
            self::fail('Revoked brand access must prevent transfer');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => '[]']);
        try {
            $transfer(fn () => DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']));
            self::fail('Archived selected brand must prevent transfer');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        self::assertSame($owner->id, $org->fresh()->owner_user_id);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $org->id, 'action' => 'organization.owner.transferred']);
    }
}
