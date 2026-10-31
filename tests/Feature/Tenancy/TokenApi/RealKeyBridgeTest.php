<?php

namespace Tests\Feature\Tenancy\TokenApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tenancy\TenancyTestCase;

/** Test-only integration of the actual E1-10 key routes with the bearer guard. */
final class RealKeyBridgeTest extends TenancyTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $actual = DB::selectOne('select current_database() as name, current_user as role, inet_server_addr() as host, inet_server_port() as port');
        if (! $app->environment('testing') || $actual->name !== 'souzpir_test_e110_api_bridge'
            || $actual->role !== 'e110_api_bridge' || (string) $actual->host !== '127.0.0.1' || (int) $actual->port !== 56541
            || $actual->name !== config('runtime.test_database') || $actual->name === config('runtime.preview_database')
            || ! str_ends_with(str_replace('\\', '/', $app->basePath()), '/.worktrees/sp-e1-10-api-integration')) {
            throw new \RuntimeException('Bridge tests require their isolated database and checkout.');
        }

        return $app;
    }

    public function test_real_key_creation_use_abilities_and_revocation(): void
    {
        Http::preventStrayRequests();
        self::assertTrue(\App\Shell\Integrations\SecretSessionStore::ready(), json_encode([
            'env' => app()->environment(), 'session' => config('session.driver'), 'cache' => config('cache.default'),
            'block' => config('session.block_store'), 'phpunit' => defined('PHPUNIT_COMPOSER_INSTALL'),
        ], JSON_THROW_ON_ERROR));
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, , $first, $second] = $this->cabinet();
        $this->post('/login', ['email' => $owner->email, 'password' => 'test-only-password'])->assertRedirect()->assertSessionHasNoErrors();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->withSession(['brand_id' => $first->id])
            ->post('/integrations/api/keys', ['name' => 'Read fixture', 'abilities' => ['read']])
            ->assertRedirect('/integrations/api')->assertSessionHasNoErrors();
        $key = $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertOk()->json('value');
        self::assertIsString($key);
        [$id] = explode('|', $key);
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/brand')->assertOk()
            ->assertJsonPath('id', $first->id);
        $this->withHeader('X-Brand-Id', $second->id)->getJson('/api/v1/brand')->assertNotFound();
        $this->flushHeaders()->delete('/integrations/api/keys/'.$id)->assertRedirect('/integrations/api');
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/brand')->assertUnauthorized();

        $this->flushHeaders()->post('/integrations/api/keys', ['name' => 'Leads fixture', 'abilities' => ['leads']])
            ->assertRedirect('/integrations/api')->assertSessionHasNoErrors();
        $leads = $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertOk()->json('value');
        self::assertIsString($leads);
        $this->withHeader('Authorization', 'Bearer '.$leads)->getJson('/api/v1/brand')->assertForbidden();
        Http::assertNothingSent();
    }
}
