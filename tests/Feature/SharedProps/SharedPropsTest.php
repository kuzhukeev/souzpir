<?php

namespace Tests\Feature\SharedProps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SharedPropsTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/shared-props-test', fn () => Inertia::render('Testing/Shared'));
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/shared-redirect-test', fn () => redirect('/'));
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/shared-exception-test', function (): never {
            throw new \RuntimeException('expected shared-props test exception');
        });
        Route::middleware('web')->get('/guest-props-test', fn () => Inertia::render('Testing/Guest'));
        Route::get('/public-props-test', fn () => Inertia::render('Testing/Public'));
    }

    public function test_authenticated_projection_is_tenant_specific_and_contains_no_private_user_attributes(): void
    {
        [$owner, $organization, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->get('/shared-props-test?secret=not-a-route-prop')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Testing/Shared', false)
            ->where('auth.user.id', $owner->id)
            ->where('auth.user.name', $owner->name)
            ->where('auth.user.email', $owner->email)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->where('organization.id', $organization->id)
            ->where('brand.id', $first->id)
            ->where('theme', 'confectionery')
            ->has('brands', 2)
            ->where('can', fn ($can) => $can['team.write'] === true)
            ->where('modules.seller', false)
            ->where('modules.vision', false)
            ->where('unread', null)
            ->where('translations', fn ($translations) => $translations['Tenancy/Access/messages.invalid_role'] === 'Недопустимая роль или состояние участника.')
            ->where('ziggy.location.search', '')
            ->where('ziggy.location.pathname', '/shared-props-test')
            ->where('request_id', fn ($id) => is_string($id) && strlen($id) > 10));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->get('/shared-props-test')->assertInertia(fn (Assert $page) => $page
            ->where('brand.id', $second->id)->where('theme', 'pyro'));
    }

    public function test_brand_appearance_follows_selected_brand_and_is_not_public(): void
    {
        [$owner, , $first, $second] = $this->cabinet();
        DB::table('brands')->where('id', $first->id)->update(['settings' => json_encode([
            'logo_path' => 'organizations/'.$first->organization_id.'/onboarding/logos/'.str_repeat('a', 32).'.png',
            'theme' => ['subtitle' => 'Первый бренд', 'promo_card' => false],
        ], JSON_THROW_ON_ERROR)]);
        DB::table('brands')->where('id', $second->id)->update(['settings' => json_encode([
            'theme' => ['subtitle' => 'Второй бренд', 'promo_card' => true],
        ], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->get('/shared-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('brandAppearance.subtitle', 'Первый бренд')
            ->where('brandAppearance.promo_card', false)
            ->where('brandAppearance.logo_url', route('brands.settings.logo', $first->id))
            ->where('ziggy.routes', fn ($routes) => isset($routes['brands.settings.show']) && rtrim($routes['brands.settings.show']['uri'], '/') === 'brands/{brand}/settings'));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->get('/shared-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('brandAppearance.subtitle', 'Второй бренд')
            ->where('brandAppearance.promo_card', true)
            ->where('brandAppearance.logo_url', null));
        $this->get('/public-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('brandAppearance'));
    }

    public function test_viewer_can_only_see_authorized_brand_and_false_permissions(): void
    {
        [, $organization, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$first->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $this->get('/shared-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('brands', 1)->where('brands.0.id', $first->id)->where('can', fn ($can) => $can['team.write'] === false));
        $this->postJson('/brand/switch', ['brand_id' => $second->id])->assertNotFound();
    }

    public function test_guest_and_preview_are_anonymous_and_public_page_is_separate(): void
    {
        $this->get('/guest-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', null)->where('organization', null)->where('brand', null)
            ->where('theme', 'neutral')->where('unread', null)->has('can', 0));
        [$owner] = $this->cabinet();
        $this->actingAs($owner)->get('/preview/attention')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('preview', true)->where('auth.user', null)->where('organization', null)->has('can', 0));
        $this->get('/public-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Testing/Public', false)->missing('auth')->missing('organization')->missing('brand')->missing('can')->missing('ziggy'));
    }

    public function test_authenticated_web_request_cannot_leak_into_next_public_request(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/shared-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $owner->id)->where('organization.id', $organization->id));
        $this->assertSame([], Inertia::getShared());
        $this->get('/public-props-test')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Testing/Public', false)->missing('auth')->missing('organization')->missing('brand')->missing('can')->missing('ziggy'));
    }

    public function test_redirect_and_exception_clear_request_scoped_shared_props(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/shared-redirect-test')->assertRedirect('/');
        $this->assertSame([], Inertia::getShared());

        $this->withoutExceptionHandling();
        try {
            $this->get('/shared-exception-test');
            $this->fail('Expected an exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('expected shared-props test exception', $exception->getMessage());
        }
        $this->assertSame([], Inertia::getShared());
    }
}
