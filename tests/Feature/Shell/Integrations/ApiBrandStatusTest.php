<?php

namespace Tests\Feature\Shell\Integrations;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class ApiBrandStatusTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_authorized_page_and_real_api_report_supported_and_unavailable_states(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/integrations/api')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Integrations/Api')->where('apiBrandReadConfigured', true)
            ->where('apiKeyRuntimeAvailable', false)->where('webhookDeliveryAvailable', false));
        $this->post('/integrations/api/keys', ['name' => 'Тест чтения', 'abilities' => ['read']])
            ->assertRedirect('/integrations/api')->assertSessionHasNoErrors();
        $key = $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertOk()->json('value');
        self::assertIsString($key);
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/brand')->assertOk()->assertJsonPath('id', $brand->id);
        $this->flushHeaders();

        config(['cache.limiter' => 'no-api-limiter', 'cache.stores.no-api-limiter.driver' => 'null']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/integrations/api')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('apiBrandReadConfigured', false)->where('apiKeyRuntimeAvailable', false)->where('webhookDeliveryAvailable', false));
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/brand')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_status_does_not_grant_access_to_non_managers(): void
    {
        Http::preventStrayRequests();
        [, $organization, $brand] = $this->cabinet();
        [$member] = $this->member($organization, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->get('/integrations/api')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unreachable_limiter_backend_does_not_claim_the_api_is_configured(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, , $brand] = $this->cabinet();
        config(['cache.limiter' => 'broken-api-limiter', 'cache.stores.broken-api-limiter' => [
            'driver' => 'database', 'connection' => 'missing-test-connection', 'table' => 'cache',
        ]]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/integrations/api')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('apiBrandReadConfigured', false));
        $this->getJson('/api/v1/brand')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_insecure_remote_transport_does_not_claim_brand_api_availability(): void
    {
        Http::preventStrayRequests();
        [$owner, , $brand] = $this->cabinet();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Transport fixture', 'abilities' => ['read']])->assertSessionHasNoErrors();
        $key = $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertOk()->json('value');
        self::assertIsString($key);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'SERVER_ADDR' => '192.0.2.10']);
        $this->get('/integrations/api')->assertOk()->assertInertia(fn (Assert $page) => $page->where('apiBrandReadConfigured', false));
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/brand')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_changed_route_or_ability_guard_fails_closed(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $route = Route::getRoutes()->getByName('api.v1.brand.show');
        self::assertNotNull($route);
        $original = $route->getAction();
        try {
            $route->setAction([...$original, 'controller' => 'UnverifiedController']);
            $this->get('/integrations/api')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('apiBrandReadConfigured', false));
        } finally {
            $route->setAction($original);
        }
        $router = app('router');
        $alias = $router->getMiddleware()['token.ability'];
        try {
            $router->aliasMiddleware('token.ability', SubstituteBindings::class);
            $this->get('/integrations/api')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('apiBrandReadConfigured', false));
        } finally {
            $router->aliasMiddleware('token.ability', $alias);
        }
        Http::assertNothingSent();
    }

    public function test_file_limiter_with_unusable_paths_fails_closed(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, , $brand] = $this->cabinet();
        config(['cache.limiter' => 'bad-file-limiter', 'cache.stores.bad-file-limiter' => [
            'driver' => 'file', 'path' => base_path('composer.json'), 'lock_path' => base_path('composer.json'),
        ]]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/integrations/api')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('apiBrandReadConfigured', false));
        $this->getJson('/api/v1/brand')->assertStatus(503);
        Http::assertNothingSent();
    }
}
