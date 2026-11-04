<?php

namespace Tests\Feature\Vision\Profile;

use App\Ai\CanonicalJson;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\SiteAudit\Knowledge\SiteDraftStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class SiteFactsE814ETest extends TestCase
{
    use IsolatedDatabaseMigrations;

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

    public function test_explicit_site_facts_are_suggestions_for_current_brand_only(): void
    {
        Http::preventStrayRequests();
        $this->withoutVite();
        $owner = User::create(['name' => 'Owner', 'email' => 'site-hint-owner@example.test', 'password' => 'test-password-only']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $editor = User::create(['name' => 'Editor', 'email' => 'site-hint-editor@example.test', 'password' => 'test-password-only']);
        $editor->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Site fixture', 'owner_user_id' => $owner->id]);
        [$ownerMember, $editorMember, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($owner, $editor): array {
            $brand = Brand::create(['name' => 'Site brand', 'website' => 'https://example.com']);

            return [Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Membership::create(['user_id' => $editor->id, 'role' => 'member', 'brand_ids' => [$brand->id]]), $brand];
        });
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $title = 'Synthetic site title';
        $text = "This is a synthetic document from the private site source.\nСертификаты:\nАдрес: Москва, Пушкина 1\nТелефон: +7 123 456 78 90\nДоставка: По России\nТипы покупателей: Организаторы; Семьи\nКатегории: Фейерверки\nКонкуренты: Магазин Искра\nВопросы клиентов: Как выбрать салют?";
        $page = ['title' => $title, 'text' => $text, 'links' => [], 'price_caveats' => [], 'fingerprint' => CanonicalJson::hash(['title' => $title, 'text' => $text])];
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $ownerMember->id, 'session');
        app(TenantContextRunner::class)->run($ctx, fn () => app(SiteDraftStore::class)->store($ctx, 'https://example.com', 'https://example.com', $page, 1));
        $this->signIn($owner, $brand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/2')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('hints.status', 'available')->where('hints.items.0.value', $title)->where('hints.items.0.source', 'https://example.com/'));
        $this->privateGet('/vision/quiz/1')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.status', 'available'));
        $this->privateGet('/vision/quiz/3')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Фейерверки')->where('hints.items.1.value', 'Организаторы')->where('hints.items.1.kind', 'customer_type')->where('hints.items.2.value', 'Семьи'));
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Магазин Искра'));
        $this->privateGet('/vision/quiz/5')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', $title)->where('hints.items.1.value', 'Москва, Пушкина 1')->where('hints.items.2.value', '+7 123 456 78 90')->where('hints.items.3.value', 'По России')->has('hints.items', 4));
        $this->privateGet('/vision/quiz/7')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.items.0.value', 'Как выбрать салют?'));
        $this->signIn($editor, $brand->id);
        $response = $this->privateGet('/vision/quiz/2')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('hints.status', 'unavailable')->where('hints.items', []));
        self::assertStringNotContainsString($title, $response->getContent());
        $otherBrand = app(TenantContextRunner::class)->run(new TenantContext($org->id), fn () => Brand::create(['name' => 'Other brand', 'website' => 'https://example.com']));
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $otherBrand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($owner, $otherBrand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $otherResponse = $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn (Assert $page) => $page->where('hints.status', 'empty')->where('hints.items', []));
        self::assertStringNotContainsString('Магазин Искра', $otherResponse->getContent());
        Http::assertNothingSent();
    }

    private function privateGet(string $path): TestResponse
    {
        $this->get($path)->assertRedirect('/vision/private-access');
        $page = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $page->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => hash('sha256', hex2bin($matches[1]))])->assertRedirect($path);

        return $this->get($path);
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
