<?php

namespace Tests\Feature\Tenancy\TokenApi;

use App\Shell\Integrations\SecretSessionStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tenancy\TenancyTestCase;

/** Test-only integration of the actual E1-10 key routes with the bearer guard. */
final class RealKeyBridgeTest extends TenancyTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $actual = DB::selectOne('select current_database() as name, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        $base = realpath($app->basePath());
        $testDatabase = config('runtime.test_database');
        if (! $app->environment('testing') || ! $base || strcasecmp($base, (string) realpath(getcwd())) !== 0
            || ! is_string($testDatabase) || ! preg_match('/(?:^|_)test(?:_|$)/', $testDatabase)
            || $actual->name !== $testDatabase || $actual->name === config('runtime.preview_database')
            || (! str_starts_with((string) $actual->host, '127.') && $actual->host !== '::1')
            || (int) $actual->port !== (int) config('database.connections.pgsql.port')
            || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole
            || ! str_starts_with(strtolower(str_replace('\\', '/', storage_path())), strtolower(str_replace('\\', '/', $base).'/.local/runtime/testing/'))) {
            throw new \RuntimeException('Bridge tests require their isolated database and checkout.');
        }

        return $app;
    }

    public function test_real_key_creation_use_abilities_and_revocation(): void
    {
        Http::preventStrayRequests();
        self::assertTrue(SecretSessionStore::ready(), json_encode([
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
