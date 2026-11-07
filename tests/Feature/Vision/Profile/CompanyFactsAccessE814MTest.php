<?php

namespace Tests\Feature\Vision\Profile;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompanyFactsAccessE814MTest extends TenancyTestCase
{
    use ResolvesDeferredHints;

    public function test_audit_facts_are_scoped_to_the_brand_and_need_access(): void
    {
        URL::useOrigin('http://127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brands')->where('id', $item->id)->update(['website' => 'https://example.test']);
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'created_by' => $owner->id, 'result' => json_encode(['url' => 'https://example.test', 'pages' => [['url' => 'https://example.test/about', 'status' => 200, 'headings' => ['Сертификаты: ISO 9001']]]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($owner, $brand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/5')->assertOk()->assertInertia(fn ($page) => $page->where('hints.items.0.label', 'Данные сайта: Сертификаты')->where('hints.items.0.value', 'ISO 9001')->where('hints.items.0.source', 'https://example.test/about'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/5')->assertOk()->assertInertia(fn ($page) => $page->where('hints.items', []));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/quiz/start')->assertForbidden();
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
