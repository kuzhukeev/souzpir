<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\SiteAudit\CrawlerClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class SiteImportsTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $user->refresh();
        parent::actingAs($user, $guard);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);

        return $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    public function withSession(array $data)
    {
        parent::withSession($data);
        $this->app['session']->save();

        return $this;
    }

    public function test_site_preview_selection_and_brand_isolation(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Site import', 'owner_user_id' => $owner->id]);
        [$brand, $other] = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), function () use ($owner) {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);

            return [Brand::create(['name' => 'A', 'theme' => 'neutral']), Brand::create(['name' => 'B', 'theme' => 'neutral'])];
        });
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        DB::table('brands')->where('id', $brand->id)->update(['website' => 'https://brand.example/']);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $crawler = Mockery::mock(CrawlerClient::class);
        $crawler->shouldReceive('fetch')->twice()->andReturnUsing(fn ($url) => str_ends_with($url, 'robots.txt') ? ['url' => $url, 'status' => 200, 'content_type' => 'text/plain', 'body' => "User-agent: *\nAllow: /"] : ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'body' => '<html><head><title>Каталог</title></head><body><p>Доставка по России от трёх дней.</p><p>Работаем без выходных.</p></body></html>']);
        app()->instance(CrawlerClient::class, $crawler);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Лист" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>Доставка</t></is></c><c r="B1" t="inlineStr"><is><t>Москва</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $this->postJson('/seller/knowledge/imports/preview', ['file' => new UploadedFile($path, 'test.xlsx', null, null, true)])->assertOk()->assertJsonPath('proposals.0', 'Доставка · Москва');
        unlink($path);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'site'])->assertRedirect();
        $data = $this->postJson('/seller/knowledge/imports/site/preview', ['url' => 'https://brand.example/'])->assertOk()->json();
        $this->assertNotEmpty($data['proposals']);
        $this->assertDatabaseCount('kn_facts', 0);
        $this->post('/seller/knowledge/imports/site', ['url' => $data['url'], 'version_id' => $data['version_id'], 'version' => 2, 'group' => 'site', 'selected' => [0]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kn_facts', ['text' => $data['proposals'][0], 'source' => 'url']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.site_fact.imported', 'brand_id' => $brand->id]);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/seller/knowledge/imports/site', ['url' => $data['url'], 'version_id' => $data['version_id'], 'version' => 2, 'group' => 'site', 'selected' => [0]])->assertNotFound();
        $this->postJson('/seller/knowledge/imports/site/preview', ['url' => 'invalid'])->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->update(['role' => 'viewer']);
        $this->post('/seller/knowledge/imports/site', ['url' => $data['url'], 'version_id' => $data['version_id'], 'version' => 3, 'group' => 'site', 'selected' => [0]])->assertNotFound();
        $this->assertDatabaseCount('kn_facts', 1);
    }
}
