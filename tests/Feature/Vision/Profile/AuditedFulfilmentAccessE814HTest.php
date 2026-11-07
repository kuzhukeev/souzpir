<?php

namespace Tests\Feature\Vision\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class AuditedFulfilmentAccessE814HTest extends TestCase
{
    use IsolatedDatabaseMigrations;
    use ResolvesDeferredHints;

    protected function beforeRefreshingDatabase(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 4));
        $database = config('runtime.test_database');
        if (! app()->environment('testing') || str_replace('\\', '/', base_path()) !== $root
            || ! is_string($database) || ! preg_match('/(?:^|_)test(?:_|$)/', $database)
            || $database === config('runtime.preview_database') || DB::connection()->getDatabaseName() !== $database
            || ! str_starts_with(str_replace('\\', '/', storage_path()), $root.'/.local/runtime/testing/')
            || ! str_starts_with(str_replace('\\', '/', app()->getCachedConfigPath()), $root.'/.local/runtime/testing/')) {
            throw new \RuntimeException('Refusing migrations outside the dedicated test checkout.');
        }
        $db = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if ($db->db !== $database || (! str_starts_with((string) $db->host, '127.') && $db->host !== '::1')
            || (int) $db->port !== (int) config('database.connections.pgsql.port') || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole) {
            throw new \RuntimeException('Refusing non-isolated database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        URL::useOrigin('http://127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
    }

    public function test_audited_fulfilment_is_scoped_to_current_brand(): void
    {
        Http::preventStrayRequests();
        $this->withoutVite();
        $owner = User::create(['name' => 'Owner', 'email' => 'site-check-owner@example.test', 'password' => 'test-password-only']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $editor = User::create(['name' => 'Editor', 'email' => 'site-check-editor@example.test', 'password' => 'test-password-only']);
        $editor->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Site fixture', 'owner_user_id' => $owner->id]);
        [$brand, $other] = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($owner, $editor): array {
            $brand = Brand::create(['name' => 'First', 'website' => 'https://example.com']);
            $other = Brand::create(['name' => 'Second', 'website' => 'https://example.com']);
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Membership::create(['user_id' => $editor->id, 'role' => 'member', 'brand_ids' => [$brand->id]]);

            return [$brand, $other];
        });
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'created_by' => $owner->id, 'result' => json_encode(['url' => 'https://example.com', 'readiness' => 74, 'crawled_pages' => 3, 'pages' => [['url' => 'https://example.com/delivery', 'status' => 200, 'fulfilment_evidence' => 'Доставка в течение двух дней', 'headings' => ['Типы покупателей: Магазины', 'Конкуренты: Сосед', 'Вопросы клиентов: Есть доставка?']]]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($owner, $brand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/1')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.site_check.readiness', 74)->where('hints.site_check.pages', 3));
        $this->privateGet('/vision/quiz/5')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Доставка в течение двух дней')->where('hints.items.0.source', 'https://example.com/delivery'));
        $this->privateGet('/vision/quiz/3')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Магазины')->where('hints.items.0.kind', 'customer_type'));
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Сосед'));
        $this->privateGet('/vision/quiz/7')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Есть доставка?'));
        $this->signIn($editor, $brand->id);
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.status', 'unavailable')->where('hints.items', []));
        $this->privateGet('/vision/quiz/1')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.status', 'unavailable')->missing('hints.site_check'));
        $this->signIn($owner, $other->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items', []));
        $this->privateGet('/vision/quiz/5')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items', []));
        $this->privateGet('/vision/quiz/1')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.site_check.readiness', null));
        Http::assertNothingSent();
    }

    private function privateGet(string $path): TestResponse
    {
        $this->get($path)->assertRedirect('/vision/private-access');
        $page = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $page->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => hash('sha256', hex2bin($matches[1]))])->assertRedirect($path);

        return $this->resolveDeferredHints($this->get($path), $path);
    }

    private function signIn(User $user, string $brandId): void
    {
        $user->refresh();
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        session()->put(['auth.version' => $user->auth_version, 'brand_id' => $brandId]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        session()->save();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }
}
