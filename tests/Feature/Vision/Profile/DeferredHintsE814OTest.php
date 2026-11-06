<?php

namespace Tests\Feature\Vision\Profile;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DeferredHintsE814OTest extends TenancyTestCase
{
    public function test_deferred_access_requires_bound_grant_and_rechecks_brand(): void
    {
        URL::useOrigin('http://127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->signIn($owner, $brand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->get('/vision/quiz/4')->assertRedirect('/vision/private-access');
        $page = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $page->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => hash('sha256', hex2bin($matches[1]))])->assertRedirect('/vision/quiz/4');
        $response = $this->get('/vision/quiz/4')->assertOk()->assertInertia(fn ($page) => $page->where('hints.status', 'loading'));
        $grant = $response->viewData('page')['props']['hints_grant'];
        self::assertIsString($grant);
        $this->getJson('/vision/quiz/4/hints')->assertForbidden();
        $this->withHeader('X-Quiz-Hints-Grant', $grant)->getJson('/vision/quiz/5/hints')->assertForbidden();
        $this->withHeader('X-Quiz-Hints-Grant', $grant)->getJson('/vision/quiz/4/hints')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', 'empty');
        $this->travel(11)->minutes();
        $this->withHeader('X-Quiz-Hints-Grant', $grant)->getJson('/vision/quiz/4/hints')->assertForbidden();
        $this->travelBack();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeader('X-Quiz-Hints-Grant', $grant)->getJson('/vision/quiz/4/hints')->assertForbidden();
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
