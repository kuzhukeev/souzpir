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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SiteHintsHttpTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::selectOne('select current_database() as db, current_user as role, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if ($db->db !== 'souzpir_test_sp_e8_02' || $db->role !== 'sp_e8_02_app' || (int) $db->port !== 55490 || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole
            || ! str_contains(str_replace('\\', '/', storage_path()), '/.worktrees/sp-e8-02/.local/runtime/testing/')) {
            throw new \RuntimeException('Refusing non-isolated database.');
        }
    }

    public function test_owner_can_choose_audited_site_title_but_editor_cannot_read_private_source(): void
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
        $text = 'This is a synthetic document from the private site source.';
        $page = ['title' => $title, 'text' => $text, 'links' => [], 'price_caveats' => [], 'fingerprint' => CanonicalJson::hash(['title' => $title, 'text' => $text])];
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $ownerMember->id, 'session');
        app(TenantContextRunner::class)->run($ctx, fn () => app(SiteDraftStore::class)->store($ctx, 'https://example.com', 'https://example.com', $page, 1));
        $this->signIn($owner, $brand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/2')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('hints.status', 'available')->where('hints.items.0.value', $title)->where('hints.items.0.source', 'https://example.com/'));
        $this->signIn($editor, $brand->id);
        $response = $this->privateGet('/vision/quiz/2')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('hints.status', 'unavailable')->where('hints.items', []));
        self::assertStringNotContainsString($title, $response->getContent());
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
