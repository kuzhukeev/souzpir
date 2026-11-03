<?php

namespace Tests\Feature\Vision\Site;

use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Site\Jobs\AuditSiteJob;
use App\Vision\Site\SiteOperations;
use App\Vision\SiteAudit\PublicAddressResolver;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SessionModuleTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function cabinet(bool $configured = true): array
    {
        $cabinet = parent::cabinet($configured);
        foreach ([$cabinet[2], $cabinet[3]] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $cabinet[1]->id,
                'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $cabinet;
    }

    public function test_revoked_session_cannot_create_update_or_delete_via_service(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $id = (string) Str::ulid();
        DB::table('actions')->insert(['id' => $id, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'title' => 'Исходное действие', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $request = Request::create('/vision/actions', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn (): User => $owner);
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, 'session');
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $service = app(SiteOperations::class);
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        Http::fake();
        app(TenantContextRunner::class)->run($context, function () use ($service, $context, $request, $id): void {
            foreach ([
                fn () => $service->create($context, $request, ['title' => 'Запрещено']),
                fn () => $service->update($context, $request, $id, ['title' => 'Запрещено']),
                fn () => $service->delete($context, $request, $id),
                fn () => $service->runAudit($context, $request, 'https://fixture.example.com', app(SiteInspector::class)),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail('An action must not be changed after the session is revoked.');
                } catch (AuthenticationException) {
                    self::assertSame('Исходное действие', DB::table('actions')->where('id', $id)->value('title'));
                    self::assertSame(1, DB::table('actions')->count());
                }
            }
        });
        self::assertSame(0, DB::table('audit_logs')->whereIn('action', ['vision.action.created', 'vision.action.updated', 'vision.action.deleted'])->count());
        Http::assertNothingSent();
    }

    public function test_disabled_vision_module_blocks_page_and_mutation(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        DB::table('brand_modules')->where('organization_id', $organization->id)->where('brand_id', $brand->id)->where('module', 'vision')->update(['enabled' => false]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/actions')->assertNotFound();
        $this->post('/vision/actions', ['title' => 'Запрещено'])->assertNotFound();
        $this->assertDatabaseCount('actions', 0);
        Http::assertNothingSent();
    }

    public function test_plan_disallowing_vision_blocks_actions_without_fabricating_a_default(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'fixture', 'name' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.vision', 'value' => 0, 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/vision/actions', ['title' => 'Недоступно'])->assertNotFound();
        $this->assertDatabaseCount('actions', 0);
        Http::assertNothingSent();
    }

    public function test_module_disabled_during_fake_crawl_prevents_audit_persistence(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        $this->app->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake(function ($request) use ($organization, $brand) {
            DB::table('brand_modules')->where('organization_id', $organization->id)->where('brand_id', $brand->id)->where('module', 'vision')->update(['enabled' => false]);
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain']);
            }
            if ($request->url() === 'https://fixture.example.com') {
                return Http::response('<html><body><h1>Тестовое предложение</h1><p>Данные HTML фикстуры.</p></body></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('', 404, ['Content-Type' => 'text/plain']);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/vision/site/audit', ['url' => 'https://fixture.example.com'])->assertRedirect()->assertSessionHasErrors('url');
        self::assertSame('failed', Cache::get(AuditSiteJob::key($organization->id, $brand->id))['stage']);
        $this->assertDatabaseCount('audit_runs', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vision.site.audit.created']);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://fixture.example.com'));
    }
}
