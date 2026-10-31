<?php

namespace Tests\Feature\Shell\Onboarding;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\Tenancy\Fixtures\TenantRecord;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OnboardingTest extends TenancyTestCase
{
    private function emptyCabinet(): array
    {
        $owner = $this->user();
        $org = Organization::create(['name' => 'Черновик компании', 'owner_user_id' => $owner->id]);
        $member = $this->within($org, null, fn () => Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']));
        config(['features.E0-02.defaults.organization_id' => $org->id]);

        return [$owner, $org, $member];
    }

    private function form(array $overrides = []): array
    {
        return array_replace(['organization_name' => 'ООО Союз Пир', 'industry' => 'Пиротехника', 'region' => 'Москва', 'timezone' => 'Europe/Moscow', 'website' => 'https://example.test', 'brand_name' => 'Союз Пир', 'alias' => 'Союз', 'theme' => 'pyro', 'seller' => true, 'vision' => false, 'step' => 4], $overrides);
    }

    public function test_draft_survives_reload_and_no_brand_is_created_until_completion(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        self::assertSame(env('DB_TEST_DATABASE'), DB::connection()->getDatabaseName());
        $this->actingAs($owner)->get('/start')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Onboarding/Start')->where('initial.step', 1)->where('initial.completed', false)->where('brand', null));
        $this->post('/start/draft', $this->form(['step' => 2]))->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/start')->assertOk()->assertInertia(fn (Assert $page) => $page->where('initial.step', 2)->where('initial.brand_name', 'Союз Пир')->where('initial.already_configured', false));
        self::assertSame(0, DB::table('brands')->count());
        self::assertSame(1, DB::table('organizations')->count());
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'onboarding.draft.saved']);
    }

    public function test_completion_is_atomic_repeatable_and_does_not_enable_modules(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        $this->actingAs($owner)->post('/start', $this->form())->assertRedirect('/start')->assertSessionHasNoErrors();
        $brand = DB::table('brands')->where('organization_id', $org->id)->sole();
        self::assertSame('Союз Пир', $brand->name);
        self::assertSame('pyro', $brand->theme);
        self::assertSame(['seller' => true, 'vision' => false], json_decode($brand->settings, true)['onboarding']['modules_requested']);
        self::assertSame('ООО Союз Пир', $org->fresh()->name);
        $this->assertDatabaseHas('onboarding_progress', ['organization_id' => $org->id, 'brand_id' => $brand->id]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'onboarding.completed', 'subject_id' => $brand->id]);
        $this->get('/start')->assertInertia(fn (Assert $page) => $page->where('initial.completed', true)->where('initial.already_configured', false)->where('modules.seller', false)->where('modules.vision', false));
        $this->post('/start', $this->form())->assertSessionHasNoErrors();
        $this->post('/start', $this->form(['brand_name' => 'Другое']))->assertSessionHasErrors('onboarding');
        self::assertSame(1, DB::table('brands')->count());
        self::assertSame(1, DB::table('organizations')->count());
    }

    public function test_replayed_completion_does_not_change_another_selected_brand(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        $this->actingAs($owner)->post('/start', $this->form())->assertSessionHasNoErrors();
        $other = $this->within($org, null, fn () => Brand::create(['name' => 'Другой бренд']));
        $this->withSession(['brand_id' => $other->id])->post('/start', $this->form())
            ->assertSessionHasNoErrors()->assertSessionHas('brand_id', $other->id);
        self::assertSame(2, DB::table('brands')->count());
    }

    public function test_first_brand_respects_assigned_plan_limit(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        $plan = (string) Str::ulid();
        $limit = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'first-brand-limit', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => $limit, 'plan_id' => $plan, 'key' => 'brands.max', 'value' => 0, 'unit' => 'brands', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->post('/start', $this->form())->assertRedirect('/start')->assertSessionHasErrors('brands.max');
        self::assertSame(0, DB::table('brands')->count());
        self::assertSame(0, DB::table('onboarding_progress')->count());
        self::assertSame('Черновик компании', $org->fresh()->name);
        DB::table('plan_limits')->where('id', $limit)->update(['value' => 1]);
        $this->post('/start', $this->form())->assertRedirect('/start')->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('brands')->count());
    }

    public function test_failed_audit_rolls_back_profile_brand_and_progress(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withoutExceptionHandling();
        try {
            $this->post('/start', $this->form());
            self::fail('Expected audit failure');
        } catch (RuntimeException $e) {
            self::assertSame('audit unavailable', $e->getMessage());
        }
        self::assertSame('Черновик компании', $org->fresh()->name);
        self::assertSame(0, DB::table('brands')->count());
        self::assertSame(0, DB::table('onboarding_progress')->count());
    }

    public function test_only_designated_verified_owner_can_access(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        $this->get('/start')->assertRedirect('/login');
        [$admin] = $this->member($org, 'admin');
        $this->actingAs($admin)->get('/start')->assertForbidden();
    }

    public function test_brand_selection_header_cannot_override_organization_only_mode(): void
    {
        [$owner] = $this->emptyCabinet();
        $this->actingAs($owner)->withHeader('X-Brand-Id', 'anything')->get('/start')->assertNotFound();
    }

    public function test_unverified_owner_cannot_open_wizard(): void
    {
        [$owner] = $this->emptyCabinet();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner)->get('/start')->assertRedirect('/email/verify');
    }

    public function test_organization_only_mode_cannot_read_brand_owned_data_or_open_brand_route(): void
    {
        [$owner, $org] = $this->emptyCabinet();
        $brand = $this->within($org, null, fn () => Brand::create(['name' => 'Existing']));
        $this->within($org, $brand, fn () => TenantRecord::create(['name' => 'secret']));
        Route::middleware(['web', 'auth', 'verified', 'onboarding.owner'])->get('/onboarding-test/scope', fn (Request $request) => response()->json(['records' => TenantRecord::count(), 'brands' => Brand::count(), 'brand' => app(TenantContext::class)->brandId]));
        $this->actingAs($owner)->get('/onboarding-test/scope')->assertOk()->assertJson(['records' => 0, 'brands' => 0, 'brand' => null]);
        $this->get('/tenancy-test/records/anything')->assertNotFound();
        $this->get('/start')->assertInertia(fn (Assert $page) => $page->where('initial.already_configured', true));
        $this->post('/start', $this->form())->assertSessionHasErrors('onboarding');
        self::assertSame(1, DB::table('brands')->count());
    }

    public function test_invalid_input_never_writes(): void
    {
        [$owner] = $this->emptyCabinet();
        $this->actingAs($owner)->post('/start', $this->form(['website' => 'file:///etc/passwd', 'theme' => 'other', 'timezone' => 'bad', 'seller' => false, 'vision' => false]))->assertSessionHasErrors(['website', 'theme', 'timezone', 'modules']);
        self::assertSame(0, DB::table('brands')->count());
        self::assertSame(0, DB::table('onboarding_progress')->count());
    }

    public function test_logo_is_private_normalized_and_attached_to_first_brand(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->emptyCabinet();
        $first = UploadedFile::fake()->image('logo.jpg', 12, 9);
        $this->actingAs($owner)->post('/start/logo', ['logo' => $first])->assertSessionHasNoErrors();
        $path = DB::table('onboarding_progress')->where('organization_id', $org->id)->value('logo_path');
        self::assertStringStartsWith('organizations/'.$org->id.'/onboarding/logos/', $path);
        Storage::disk('local')->assertExists($path);
        self::assertSame('image/png', getimagesizefromstring(Storage::disk('local')->get($path))['mime']);
        $this->get('/start/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/start')->assertInertia(fn (Assert $page) => $page->whereType('initial.logo_url', 'string'));
        $this->post('/start', $this->form())->assertSessionHasNoErrors();
        $brand = DB::table('brands')->where('organization_id', $org->id)->sole();
        self::assertSame($path, json_decode($brand->settings, true)['logo_path']);
    }

    public function test_logo_rejects_svg_corruption_oversize_and_foreign_owner(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->emptyCabinet();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($owner)->post('/start/logo', ['logo' => $svg])->assertSessionHasErrors('logo');
        $this->post('/start/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', 'not an image')])->assertSessionHasErrors('logo');
        $this->post('/start/logo', ['logo' => UploadedFile::fake()->create('large.png', 1025, 'image/png')])->assertSessionHasErrors('logo');
        self::assertSame(0, DB::table('onboarding_progress')->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_admin_cannot_upload_onboarding_logo(): void
    {
        Storage::fake('local');
        [, $org] = $this->emptyCabinet();
        [$admin] = $this->member($org, 'admin');
        $this->actingAs($admin)->post('/start/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_logo_failure_keeps_old_file_and_does_not_commit_new_path(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->emptyCabinet();
        $this->actingAs($owner)->post('/start/logo', ['logo' => UploadedFile::fake()->image('old.png')])->assertSessionHasNoErrors();
        $old = DB::table('onboarding_progress')->where('organization_id', $org->id)->value('logo_path');
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->post('/start/logo', ['logo' => UploadedFile::fake()->image('new.png')]);
            self::fail('Expected audit failure');
        } catch (RuntimeException $e) {
            self::assertSame('audit unavailable', $e->getMessage());
        }
        self::assertSame($old, DB::table('onboarding_progress')->where('organization_id', $org->id)->value('logo_path'));
        self::assertSame([$old], Storage::disk('local')->allFiles());
    }

    public function test_site_url_rejects_embedded_credentials(): void
    {
        [$owner] = $this->emptyCabinet();
        $this->actingAs($owner)->post('/start', $this->form(['website' => 'https://user:password@example.test']))->assertSessionHasErrors('website');
        self::assertSame(0, DB::table('brands')->count());
    }

    public function test_validation_returns_to_wizard_even_when_logo_was_last_visited(): void
    {
        Storage::fake('local');
        [$owner] = $this->emptyCabinet();
        $this->actingAs($owner)->post('/start/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
        $this->get('/start/logo')->assertOk();
        $this->from('/start/logo')->post('/start/logo', ['logo' => UploadedFile::fake()->createWithContent('bad.svg', '<svg/>')])->assertRedirect('/start')->assertSessionHasErrors('logo');
        $this->from('/start/logo')->post('/start', $this->form(['brand_name' => '']))->assertRedirect('/start')->assertSessionHasErrors('brand_name');
    }
}
