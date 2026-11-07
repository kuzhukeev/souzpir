<?php

namespace Tests\Feature\Shell\HomeEntry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class HomeEntryTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_guest_is_redirected_and_route_is_named_once(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertSame('/dashboard', route('dashboard', absolute: false));
        $this->assertCount(1, collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === 'dashboard'));
        Http::assertNothingSent();
    }

    public function test_real_brand_and_destinations_obey_role_and_tenant(): void
    {
        [$owner, $organization, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$first->id]);
        [$editor] = $this->member($organization, 'member', [$first->id]);
        [$foreign, , $foreignBrand] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $response = $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Dashboard/Index')->where('brand.id', $first->id)->has('homeLinks', 4));
        $links = collect($response->inertiaProps('homeLinks'))->keyBy('id');
        $this->assertSame('/team', $links['team']['href']);
        $this->assertSame('/journal', $links['journal']['href']);
        foreach ($links as $link) {
            $this->get($link['href'])->assertOk();
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('brand.id', $second->id));

        $response = $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/dashboard')->assertOk();
        $viewerIds = collect($response->inertiaProps('homeLinks'))->pluck('id')->all();
        $this->assertContains('team', $viewerIds);
        $this->assertNotContains('journal', $viewerIds);
        $this->assertNotContains('company', $viewerIds);
        foreach ($response->inertiaProps('homeLinks') as $link) {
            $this->get($link['href'])->assertOk();
        }
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($second->id, $content);
        $this->assertStringNotContainsString($foreignBrand->id, $content);
        $this->get('/dashboard', ['X-Brand-Id' => $second->id])->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertNotFound();

        $editorPage = $this->actingAs($editor)->withSession(['brand_id' => $first->id])->get('/dashboard')->assertOk();
        $editorIds = collect($editorPage->inertiaProps('homeLinks'))->pluck('id')->all();
        $this->assertNotContains('team', $editorIds);
        $this->assertContains('journal', $editorIds);
        $this->assertNotContains('company', $editorIds);
        foreach ($editorPage->inertiaProps('homeLinks') as $link) {
            $this->get($link['href'])->assertOk();
        }
        // SP-L1-07: a foreign organization's own owner now resolves into their own
        // cabinet (not the installation default) and legitimately reaches their own dashboard.
        $this->actingAs($foreign)->withSession(['brand_id' => $foreignBrand->id])->get('/dashboard')->assertOk();
        Http::assertNothingSent();
    }

    public function test_disabled_member_and_revoked_session_are_rejected(): void
    {
        [, $organization, $first] = $this->cabinet();
        [$viewer, $membership] = $this->member($organization, 'viewer', [$first->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/dashboard')->assertOk();
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        $this->get('/dashboard')->assertForbidden();
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'active']);
        $viewer->forceFill(['auth_version' => $viewer->auth_version + 1])->save();
        $this->getJson('/dashboard')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
