<?php

namespace Tests\Feature\Shell\Integrations;

use App\Shell\Integrations\SecretEnvelope;
use App\Shell\Integrations\SecretSessionStore;
use App\Shell\Integrations\SecretTransport;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SecretSecurityTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['session.encrypt' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost']);
    }

    public function test_storage_configuration_rejects_replayable_and_unshared_drivers(): void
    {
        self::assertTrue(SecretSessionStore::ready()); // explicitly limited in-process test fixture
        $this->app->instance('env', 'production');
        try {
            config(['session.driver' => 'cookie', 'cache.default' => 'database']);
            self::assertFalse(SecretSessionStore::ready());
            config(['session.driver' => 'file', 'cache.default' => 'file', 'features.E1-10.defaults.single_node_locks' => true]);
            self::assertFalse(SecretSessionStore::ready());
            config(['session.driver' => 'database', 'cache.default' => 'array']);
            self::assertFalse(SecretSessionStore::ready());
            config(['session.driver' => 'database', 'cache.default' => 'database']);
            self::assertTrue(SecretSessionStore::ready());
            config(['database.connections.sqlite.driver' => 'sqlite', 'session.connection' => 'sqlite']);
            self::assertFalse(SecretSessionStore::ready());
            config(['session.connection' => null, 'session.driver' => 'file', 'cache.default' => 'file']);
            $this->app->instance('env', 'local');
            config(['features.E1-10.defaults.single_node_locks' => false]);
            self::assertFalse(SecretSessionStore::ready());
            config(['features.E1-10.defaults.single_node_locks' => true]);
            self::assertTrue(SecretSessionStore::ready());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_insecure_session_storage_is_rejected_before_key_or_webhook_creation(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        config(['cache.default' => 'file']); // array session plus non-array lock is not an allowed PHPUnit fixture.
        $this->post('/integrations/api/keys', ['name' => 'Denied', 'abilities' => ['read']])->assertStatus(503);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']])->assertStatus(503);
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertStatus(503);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('webhooks', 0);
    }

    public function test_spoofed_local_host_cannot_create_or_consume_secrets_over_http(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $sealed = session('integration_api_key');
        $plain = json_decode(Crypt::decryptString($sealed), true, flags: JSON_THROW_ON_ERROR)['value'];
        self::assertStringNotContainsString($plain, serialize(session()->all()));
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '203.0.113.12', 'SERVER_ADDR' => '127.0.0.1']);
        $this->post('/integrations/api/keys', ['name' => 'Denied', 'abilities' => ['read']])->assertForbidden();
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']])->assertForbidden();
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('webhooks', 0);
        Http::assertNothingSent();
    }

    public function test_loopback_rejects_forwarded_headers_and_wrong_local_socket(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.12'])->post('/integrations/api/keys', ['name' => 'No', 'abilities' => ['read']])->assertForbidden();
        $this->flushHeaders();
        $this->withServerVariables(['SERVER_ADDR' => '192.0.2.11'])->post('/integrations/api/keys', ['name' => 'No', 'abilities' => ['read']])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_forwarded_https_from_untrusted_remote_does_not_enable_secret_actions(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '203.0.113.12', 'SERVER_ADDR' => '127.0.0.1']);
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->post('/integrations/api/keys', ['name' => 'Spoof', 'abilities' => ['read']])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_loopback_http_is_disabled_in_production(): void
    {
        $request = Request::create('http://localhost/integrations/api/secrets', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $this->app->instance('env', 'production');
        try {
            self::assertFalse(SecretTransport::allows($request));
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_https_works_without_loopback(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->withServerVariables(['HTTP_HOST' => 'cabinet.example.test', 'REMOTE_ADDR' => '203.0.113.12', 'SERVER_ADDR' => '192.0.2.11', 'HTTPS' => 'on', 'SERVER_PORT' => 443]);
        $this->post('https://cabinet.example.test/integrations/api/keys', ['name' => 'HTTPS', 'abilities' => ['read']])->assertRedirect();
        $this->postJson('https://cabinet.example.test/integrations/api/secrets', ['type' => 'api_key'])->assertOk();
    }

    public function test_tampered_or_cross_type_ciphertext_cannot_be_redeemed(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $sealed = session('integration_api_key');
        $this->withSession(['webhook_secret' => $sealed]);
        $this->postJson('/integrations/api/secrets', ['type' => 'webhook_secret'])->assertNotFound();
        $this->withSession(['integration_api_key' => substr($sealed, 0, -2).'xx']);
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_restored_old_flash_cannot_replay_a_consumed_secret(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $stale = session('integration_api_key');
        $plain = json_decode(Crypt::decryptString($stale), true, flags: JSON_THROW_ON_ERROR)['value'];
        self::assertStringNotContainsString($plain, json_encode(DB::table('integration_secret_receipts')->get(), JSON_THROW_ON_ERROR));
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertOk()->assertJsonPath('value', $plain);
        $this->withSession(['integration_api_key' => $stale]);
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();
        self::assertNotNull(DB::table('integration_secret_receipts')->value('consumed_at'));
        $this->withSession(['integration_api_key' => $stale]);
        $this->get('/integrations/api')->assertOk()->assertInertia(fn ($page) => $page->where('integration_api_key_available', false));
    }

    public function test_expired_receipt_prevents_display_even_when_flash_is_restored(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        DB::table('integration_secret_receipts')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();
    }

    public function test_failed_receipt_storage_rolls_back_key_and_webhook_creation(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $remaining = 2;
        DB::listen(function (QueryExecuted $query) use (&$remaining): void {
            if ($remaining > 0 && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'integration_secret_receipts')) {
                $remaining--;
                throw new RuntimeException('Simulated receipt failure.');
            }
        });
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertStatus(500);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']])->assertStatus(500);
        self::assertSame(0, $remaining);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('webhooks', 0);
        $this->assertDatabaseCount('integration_secret_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_ciphertext_is_bound_to_session_and_actor(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $sealed = session('integration_api_key');
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, 'session');
        self::assertNotNull(app(SecretEnvelope::class)->open($request, $context, 'integration_api_key', $sealed));
        $wrong = new TenantContext($organization->id, $brand->id, (string) Str::ulid(), $membership->id, 'session');
        self::assertNull(app(SecretEnvelope::class)->open($request, $wrong, 'integration_api_key', $sealed));
        session()->regenerate();
        self::assertNull(app(SecretEnvelope::class)->open($request, $context, 'integration_api_key', $sealed));
    }

    public function test_revoked_session_after_initial_gate_cannot_receive_secret(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $changed = false;
        Gate::after(function ($user, string $ability) use (&$changed, $owner): void {
            if (! $changed && $ability === 'brand.write') {
                $changed = true;
                DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
            }
        });
        $response = $this->postJson('/integrations/api/secrets', ['type' => 'api_key']);
        self::assertTrue($changed);
        $response->assertUnauthorized();
        self::assertArrayNotHasKey('value', $response->json());
    }

    public function test_membership_revoked_after_initial_gate_cannot_receive_secret(): void
    {
        [$owner, , $brand, , $membership] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $changed = false;
        Gate::after(function ($user, string $ability) use (&$changed, $membership): void {
            if (! $changed && $ability === 'brand.write') {
                $changed = true;
                DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
            }
        });
        $response = $this->postJson('/integrations/api/secrets', ['type' => 'api_key']);
        self::assertTrue($changed);
        $response->assertForbidden();
        self::assertArrayNotHasKey('value', $response->json());
    }

    public function test_brand_disabled_after_initial_gate_cannot_receive_secret(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/keys', ['name' => 'Key', 'abilities' => ['read']])->assertRedirect();
        $changed = false;
        Gate::after(function ($user, string $ability) use (&$changed, $brand): void {
            if (! $changed && $ability === 'brand.write') {
                $changed = true;
                DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
            }
        });
        $response = $this->postJson('/integrations/api/secrets', ['type' => 'api_key']);
        self::assertTrue($changed);
        $response->assertForbidden();
        self::assertArrayNotHasKey('value', $response->json());
    }
}
