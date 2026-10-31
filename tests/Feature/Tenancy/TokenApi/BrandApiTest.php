<?php

namespace Tests\Feature\Tenancy\TokenApi;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Tenancy\TokenApi\BearerBoundary;
use App\Tenancy\TokenApi\BrandAccessToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrandApiTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $base = realpath($app->basePath());
        $cwd = realpath(getcwd());
        $runtime = realpath($app->basePath('.local/runtime/testing'));
        $storage = realpath($app->storagePath());
        $actual = DB::selectOne('select current_database() as name, current_user as role');
        if ($app->environment() !== 'testing' || ! $base || ! $cwd || ! $runtime || ! $storage
            || strcasecmp($base, $cwd) !== 0 || ! str_starts_with(strtolower($storage), strtolower($runtime).DIRECTORY_SEPARATOR)
            || $actual->name !== config('runtime.test_database') || $actual->name === config('runtime.preview_database')
            || ! preg_match('/(?:^|_)test(?:_|$)/', $actual->name)) {
            throw new \RuntimeException('API tests require isolated testing runtime and database.');
        }
        if (strcasecmp($base, (string) realpath('C:/OSPanel/home/souzpir.local/.worktrees/sp-api-guard')) === 0
            && ($actual->name !== 'souzpir_test_sp_api_guard' || $actual->role !== 'souzpir_sp_api_guard')) {
            throw new \RuntimeException('API worktree must use its own isolated role and database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('tokenable_type');
                $table->ulid('tokenable_id');
                $table->ulid('organization_id');
                $table->ulid('brand_id');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->string('token_last_four', 4);
                $table->text('abilities')->nullable();
                $table->timestampTz('last_used_at')->nullable();
                $table->timestampTz('expires_at')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function test_read_requires_bearer_and_scope_and_isolation(): void
    {
        [$org, $brand] = $this->brand('First');
        [$otherOrg, $otherBrand] = $this->brand('Other');
        $read = $this->token($org, $brand, ['read']);
        $leads = $this->token($org, $brand, ['leads']);
        $this->getJson('/api/v1/brand')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$read)->getJson('/api/v1/brand')
            ->assertOk()->assertExactJson(['id' => $brand->id, 'name' => 'First'])
            ->assertHeader('Cache-Control', 'no-store, private');
        self::assertNotNull(DB::table('personal_access_tokens')->where('id', explode('|', $read)[0])->value('last_used_at'));
        $this->withHeader('X-Brand-Id', $otherBrand->id)->withHeader('Authorization', 'Bearer '.$read)
            ->getJson('/api/v1/brand')->assertNotFound();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$leads)->getJson('/api/v1/brand')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer 999|'.str_repeat('X', 64))->getJson('/api/v1/brand')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->token($otherOrg, $otherBrand, ['read']))->getJson('/api/v1/brand')
            ->assertOk()->assertJsonPath('id', $otherBrand->id);
        $this->assertSame($org->id, $brand->organization_id);
    }

    public function test_expired_and_revoked_tokens_and_inactive_scope_fail_closed(): void
    {
        [$org, $brand] = $this->brand('First');
        $expired = $this->token($org, $brand, ['read'], now()->subMinute());
        $active = $this->token($org, $brand, ['read']);
        $this->withHeader('Authorization', 'Bearer '.$expired)->getJson('/api/v1/brand')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$active)->getJson('/api/v1/brand')->assertOk();
        DB::table('personal_access_tokens')->where('id', explode('|', $active)[0])->delete();
        $this->withHeader('Authorization', 'Bearer '.$active)->getJson('/api/v1/brand')->assertUnauthorized();
        $new = $this->token($org, $brand, ['read']);
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
        $this->withHeader('Authorization', 'Bearer '.$new)->getJson('/api/v1/brand')->assertNotFound();
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'active']);
        DB::table('organizations')->where('id', $org->id)->update(['status' => 'disabled']);
        $this->withHeader('Authorization', 'Bearer '.$new)->getJson('/api/v1/brand')->assertNotFound();
    }

    public function test_digest_cannot_be_replayed_as_a_key_and_malformed_tokens_are_rejected(): void
    {
        [$org, $brand] = $this->brand('First');
        $raw = $this->token($org, $brand, ['read']);
        [$id, $secret] = explode('|', $raw);
        $this->withHeader('Authorization', 'Bearer '.$id.'|'.hash('sha256', $secret))->getJson('/api/v1/brand')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$id.'|short')->getJson('/api/v1/brand')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.str_repeat('9', 10000))->getJson('/api/v1/brand')->assertUnauthorized();
    }

    public function test_scopes_from_another_brand_and_wildcard_are_not_accepted(): void
    {
        [$org, $brand] = $this->brand('First');
        $wrong = $this->token($org, $brand, ['read']);
        $id = explode('|', $wrong)[0];
        DB::table('personal_access_tokens')->where('id', $id)->update(['tokenable_type' => User::class]);
        $this->withHeader('Authorization', 'Bearer '.$wrong)->getJson('/api/v1/brand')->assertUnauthorized();
        $star = $this->token($org, $brand, ['*']);
        $this->withHeader('Authorization', 'Bearer '.$star)->getJson('/api/v1/brand')->assertForbidden();
    }

    public function test_browser_session_without_bearer_does_not_authenticate_api(): void
    {
        $user = User::create(['name' => 'Browser', 'email' => 'browser@example.test', 'password' => 'synthetic-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        [$org] = $this->brand('Browser');
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $this->post('/login', ['email' => 'browser@example.test', 'password' => 'synthetic-password'])->assertRedirect();
        self::assertTrue(auth('web')->check());
        $this->getJson('/api/v1/brand')->assertUnauthorized();
    }

    public function test_request_contains_only_digest_and_api_failures_do_not_echo_raw_bearer(): void
    {
        [$org, $brand] = $this->brand('First');
        $raw = $this->token($org, $brand, ['read']);
        [$id, $secret] = explode('|', $raw);
        Route::get('/api/v1/synthetic', function (Request $request) {
            return response()->json(['header' => $request->header('Authorization'),
                'server' => $request->server->get('HTTP_AUTHORIZATION')]);
        });
        $this->withHeader('Authorization', 'Bearer '.$raw)->getJson('/api/v1/synthetic')
            ->assertOk()->assertExactJson(['header' => 'Bearer '.$id.'|'.hash('sha256', $secret), 'server' => null]);
        $this->withHeader('Authorization', 'Bearer '.$raw)->getJson('/api/v1/%62rand')->assertOk();
        config(['app.debug' => true]);
        $logged = [];
        $this->app['events']->listen(MessageLogged::class, function ($event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });
        Schema::drop('personal_access_tokens');
        $result = $this->withHeader('Authorization', 'Bearer '.$raw)->getJson('/api/v1/brand');
        $result->assertStatus(500);
        self::assertStringNotContainsString($secret, $result->getContent());
        self::assertStringNotContainsString($raw, $result->getContent());
        self::assertNotEmpty($logged);
        self::assertStringNotContainsString($secret, json_encode($logged, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function test_revocation_between_guard_and_scoped_action_is_rechecked(): void
    {
        [$org, $brand] = $this->brand('First');
        $raw = $this->token($org, $brand, ['read']);
        $id = explode('|', $raw)[0];
        $deleted = false;
        DB::listen(function ($query) use (&$deleted, $id): void {
            if (! $deleted && str_contains($query->sql, 'personal_access_tokens')
                && str_contains($query->sql, 'tokenable_type') && str_starts_with(strtolower($query->sql), 'select')) {
                $deleted = true;
                DB::table('personal_access_tokens')->where('id', $id)->delete();
            }
        });
        $this->withHeader('Authorization', 'Bearer '.$raw)->getJson('/api/v1/brand')->assertUnauthorized();
        self::assertTrue($deleted);
    }

    public function test_insecure_network_and_forwarded_loopback_cannot_expose_a_bearer(): void
    {
        [$org, $brand] = $this->brand('First');
        $raw = $this->token($org, $brand, ['read']);
        $this->withHeader('Authorization', 'Bearer '.$raw)
            ->withHeader('Host', '127.0.0.1')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20'])
            ->getJson('/api/v1/brand')->assertForbidden();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$raw)->withHeader('X-Forwarded-For', '198.51.100.10')
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])
            ->getJson('/api/v1/brand')->assertForbidden();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer '.$raw)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20'])
            ->getJson('https://localhost/api/v1/brand')->assertOk();
    }

    public function test_forwarded_https_requires_explicit_proxy_even_for_forge_host(): void
    {
        $secret = str_repeat('T', 64);
        $make = static fn () => Request::create('http://x.on-forge.com/api/v1/brand', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_AUTHORIZATION' => 'Bearer 1|'.$secret,
        ]);
        $proxy = new TrustProxies;
        $boundary = app(BearerBoundary::class);
        $dispatch = static fn ($request) => $boundary->handle($request, static fn () => response()->noContent());
        try {
            config(['trustedproxy.proxies' => null]);
            $proxy->handle($make(), static function ($request) use ($dispatch, $secret): void {
                self::assertTrue($request->isSecure()); // Laravel automatically trusts Forge hosts.
                $response = $dispatch($request);
                self::assertSame(403, $response->getStatusCode());
                self::assertStringNotContainsString($secret, $response->getContent());
                self::assertNull($request->headers->get('Authorization'));
                self::assertNull($request->server->get('HTTP_AUTHORIZATION'));
            });
            config(['trustedproxy.proxies' => ['198.51.100.10']]);
            $proxy->handle($make(), static function ($request) use ($dispatch): void {
                self::assertSame(204, $dispatch($request)->getStatusCode());
            });
            foreach ([['*'], ['**'], ['REMOTE_ADDR'], ['PRIVATE_SUBNETS']] as $unbounded) {
                config(['trustedproxy.proxies' => $unbounded]);
                $proxy->handle($make(), static function ($request) use ($dispatch): void {
                    self::assertSame(403, $dispatch($request)->getStatusCode());
                });
            }
        } finally {
            config(['trustedproxy.proxies' => null]);
            Request::setTrustedProxies([], 0);
        }
    }

    public function test_database_digest_is_not_a_standalone_authentication_capability(): void
    {
        [$org, $brand] = $this->brand('First');
        $raw = $this->token($org, $brand, ['read']);
        [$id, $secret] = explode('|', $raw);
        self::assertNull(BrandAccessToken::findToken($id.'|'.hash('sha256', $secret)));
    }

    private function brand(string $name): array
    {
        $organization = Organization::create(['name' => 'Fixture']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), fn () => Brand::create(['name' => $name, 'theme' => 'neutral']));

        return [$organization, $brand];
    }

    private function token(Organization $organization, Brand $brand, array $abilities, mixed $expires = null): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'name' => 'synthetic', 'token' => hash('sha256', $secret), 'token_last_four' => substr($secret, -4),
            'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'expires_at' => $expires,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id.'|'.$secret;
    }
}
