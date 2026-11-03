<?php

namespace Tests\Feature\Shell\Billing;

use App\Http\Middleware\HandleInertiaRequests;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Billing\SubmissionScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BillingWorkflowTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // PHP-only CI has no built asset manifest; supply a stable Inertia version.
        config(['app.asset_url' => 'http://127.0.0.1']);
        app('url')->forceScheme('https');
        Http::preventStrayRequests();
    }

    public function test_real_seller_usage_respects_tenant_brand_and_half_open_period_and_missing_sources_stay_unavailable(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->conversation($org->id, $brand->id, '2026-10-01 00:00:00+00');
        $this->conversation($org->id, $brand->id, '2026-10-31 23:59:59+00');
        $this->conversation($org->id, $brand->id, '2026-11-01 00:00:00+00');
        $this->conversation($org->id, $other->id, '2026-10-15 12:00:00+00');
        $this->conversation($foreignOrg->id, $foreignBrand->id, '2026-10-15 12:00:00+00');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/billing/usage?from=2026-10-01&to=2026-10-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Billing/Index')->where('usage.0.key', 'seller.dialogues')->where('usage.0.value', 2)
            ->where('usage.0.status', 'ready')->where('usage.1.value', 0)->where('usage.1.status', 'ready')
            ->where('usage.2.value', 0)->where('usage.3.value', null)->where('period.from', '2026-10-01')->where('period.to', '2026-10-31'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/billing/usage?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('usage.0.value', 1));
    }

    public function test_current_plan_and_null_and_zero_limits_come_from_configuration(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'fixture', 'name' => 'Тестовый план', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['brands.max' => '0', 'seller.dialogs_month' => null] as $key => $value) {
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => $key, 'value' => $value, 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('currentPlan.name', 'Тестовый план')->where('limits.0.value', '0.0000')->where('limits.0.used', null)
            ->where('limits.1.value', null)->where('limits.2.key', 'vision.runs_per_week')->where('limits.2.value', null)
            ->where('requestEnabled', true));
    }

    public function test_payer_is_owner_only_validated_audited_and_preserves_other_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        DB::table('organizations')->where('id', $org->id)->update(['settings' => json_encode(['other' => ['keep' => true], 'payer' => ['legacy_field' => 'keep']], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $headers = $this->payerHeaders($org, $owner, $brand);
        $this->put('/billing/payer', ['legal_name' => 'Компания', 'inn' => '123', 'kpp' => 'bad', 'email' => 'bad'], $headers)
            ->assertSessionHasErrors(['inn', 'kpp', 'email'])->assertSessionMissing('_old_input.inn');
        $this->put('/billing/payer', ['legal_name' => 'Компания', 'inn' => '1234567890', 'kpp' => '123456789', 'address' => 'Тестовый адрес', 'email' => 'billing@example.test'], $headers)
            ->assertRedirect('/billing/payer')->assertSessionHasNoErrors();
        $settings = json_decode(DB::table('organizations')->where('id', $org->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($settings['other']['keep']);
        self::assertSame('1234567890', $settings['payer']['inn']);
        self::assertSame('keep', $settings['payer']['legacy_field']);
        self::assertTrue(Str::isUlid($settings['payer']['_revision']));
        self::assertArrayNotHasKey('payer_revision', $settings);
        $this->getJson('/billing/payer/data', $this->scopeHeaders($org, $owner, $brand))->assertJsonMissingPath('data._revision')->assertJsonMissingPath('data.legacy_field');
        $audit = DB::table('audit_logs')->where('action', 'billing.payer.updated')->sole();
        self::assertStringNotContainsString('1234567890', json_encode($audit, JSON_THROW_ON_ERROR));
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('payer')->where('canManage', false));
        $this->get('/billing/payer')->assertForbidden();
        $this->put('/billing/payer', ['legal_name' => 'Нет'])->assertForbidden();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('payer')->where('canManage', false));
        $this->get('/billing/plans')->assertForbidden();
    }

    public function test_payer_audit_failure_rolls_back(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        $headers = $this->payerHeaders($org, $owner, $brand);
        try {
            $this->put('/billing/payer', ['legal_name' => 'Не сохранять'], $headers);
            self::fail('Audit failure must roll back payer.');
        } catch (\RuntimeException $error) {
            self::assertSame('Не удалось сохранить реквизиты.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        $settings = json_decode(DB::table('organizations')->where('id', $org->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('payer', $settings);
        $this->assertDatabaseCount('tickets', 0);
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_sql_failure_does_not_leak_payer_bindings_or_trace(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        $headers = $this->payerHeaders($org, $owner, $brand);
        DB::listen(static function ($event): void {
            if (str_starts_with(strtolower($event->sql), 'update "organizations"')) {
                throw new QueryException('pgsql', $event->sql, $event->bindings, new \RuntimeException('fixture database failure'));
            }
        });
        try {
            $this->put('/billing/payer', ['inn' => '1234567890', 'address' => 'PRIVATE-ADDRESS-MARKER'], $headers);
            self::fail('Database failure must be reported safely.');
        } catch (\RuntimeException $error) {
            self::assertSame('Не удалось сохранить реквизиты.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('1234567890', $error->getMessage().$error->getTraceAsString());
            self::assertStringNotContainsString('PRIVATE-ADDRESS-MARKER', $error->getMessage().$error->getTraceAsString());
        }
        $settings = json_decode(DB::table('organizations')->where('id', $org->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('payer', $settings);
    }

    public function test_designated_owner_id_alone_never_exposes_payer_without_manage_role(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('organizations')->where('id', $org->id)->update(['settings' => json_encode(['payer' => ['inn' => '1234567890']], JSON_THROW_ON_ERROR)]);
        DB::table('memberships')->where('id', $membership->id)->update(['role' => 'admin']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('payer')->where('canManage', false));
        $this->get('/billing/payer')->assertForbidden();
    }

    public function test_payer_is_absent_from_every_inertia_response_and_loaded_only_on_private_endpoint(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('organizations')->where('id', $org->id)->update(['settings' => json_encode(['payer' => [
            'inn' => '1234567890', 'address' => 'PRIVATE-ADDRESS-MARKER',
        ]], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach (['/billing', '/billing/usage', '/billing/plans', '/billing/invoices', '/billing/payer'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('PRIVATE-ADDRESS-MARKER')->assertDontSee('1234567890')
                ->assertInertia(fn (Assert $page) => $page->missing('payer'));
            $version = app(HandleInertiaRequests::class)->version(Request::create($url));
            $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->assertDontSee('PRIVATE-ADDRESS-MARKER')->assertDontSee('1234567890')
                ->assertJsonMissingPath('props.payer');
        }
        $response = $this->getJson('/billing/payer/data', $this->scopeHeaders($org, $owner, $brand))
            ->assertOk()->assertJsonPath('data.inn', '1234567890')->assertJsonPath('data.address', 'PRIVATE-ADDRESS-MARKER')
            ->assertJsonPath('data.legal_name', '');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_payer_endpoint_checks_role_context_organization_logout_and_revoked_session(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        DB::table('organizations')->where('id', $org->id)->update(['settings' => json_encode(['payer' => ['inn' => '1234567890']], JSON_THROW_ON_ERROR)]);
        DB::table('organizations')->where('id', $foreignOrg->id)->update(['settings' => json_encode(['payer' => ['inn' => '9876543210']], JSON_THROW_ON_ERROR)]);
        $headers = $this->scopeHeaders($org, $owner, $brand);
        foreach ([$admin, $viewer] as $actor) {
            $this->actingAs($actor)->withSession(['brand_id' => $brand->id]);
            $this->getJson('/billing/payer/data', $headers)->assertForbidden()->assertDontSee('1234567890');
            $this->putJson('/billing/payer', ['inn' => '1234567890'], $headers)->assertForbidden();
        }
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->getJson('/billing/payer/data', $headers)->assertConflict()->assertDontSee('1234567890');
        $this->putJson('/billing/payer', ['inn' => '9999999999'], $headers)->assertConflict();
        $this->getJson('/billing/payer/data')->assertConflict();
        config(['features.E0-02.defaults.organization_id' => $foreignOrg->id]);
        $this->actingAs($foreignOwner)->withSession(['brand_id' => $foreignBrand->id]);
        $this->getJson('/billing/payer/data', $headers)->assertConflict()->assertDontSee('9876543210');
        $this->getJson('/billing/payer/data', $this->scopeHeaders($foreignOrg, $foreignOwner, $foreignBrand))
            ->assertOk()->assertJsonPath('data.inn', '9876543210');
        DB::table('auth_session_registry')->where('user_id', $foreignOwner->id)->update(['revoked_at' => now()]);
        $this->getJson('/billing/payer/data', $this->scopeHeaders($foreignOrg, $foreignOwner, $foreignBrand))->assertUnauthorized();
        auth()->logout();
        $this->getJson('/billing/payer/data', $headers)->assertUnauthorized();
    }

    public function test_payer_json_validation_and_success_do_not_echo_sensitive_input(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $headers = $this->payerHeaders($org, $owner, $brand);
        $this->putJson('/billing/payer', ['inn' => 'PRIVATE-INVALID-MARKER', 'address' => 'PRIVATE-ADDRESS-MARKER'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('inn')->assertDontSee('PRIVATE-INVALID-MARKER')
            ->assertDontSee('PRIVATE-ADDRESS-MARKER')->assertSessionMissing('_old_input');
        $response = $this->putJson('/billing/payer', ['inn' => '1234567890', 'address' => 'PRIVATE-ADDRESS-MARKER'], $headers)
            ->assertOk()->assertJsonPath('data.saved', true)->assertDontSee('1234567890')->assertDontSee('PRIVATE-ADDRESS-MARKER');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_payer_read_sql_failure_does_not_leak_sensitive_exception(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        DB::listen(static function ($event): void {
            if (str_contains(strtolower($event->sql), 'from "organizations"') && str_contains(strtolower($event->sql), 'for update')) {
                throw new QueryException('pgsql', 'select ? as secret', ['PRIVATE-ADDRESS-MARKER'], new \RuntimeException('sensitive database failure'));
            }
        });
        try {
            $this->getJson('/billing/payer/data', $this->scopeHeaders($org, $owner, $brand));
            self::fail('Database failure must be reported safely.');
        } catch (\RuntimeException $error) {
            self::assertSame('Не удалось загрузить реквизиты.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE-ADDRESS-MARKER', $error->getMessage().$error->getTraceAsString());
        }
    }

    public function test_delayed_independent_payer_write_cannot_overwrite_newer_save_even_after_values_return(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $scope = $this->scopeHeaders($org, $owner, $brand);
        // Two independent page loads race from the same initial snapshot.
        $old = $this->payerHeaders($org, $owner, $brand);
        $new = $this->payerHeaders($org, $owner, $brand);
        self::assertSame($old['X-Payer-Revision'], $new['X-Payer-Revision']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $old['X-Payer-Revision']);
        $this->putJson('/billing/payer', ['legal_name' => 'New'], $scope)->assertConflict();
        $this->putJson('/billing/payer', ['legal_name' => 'New'], $new)->assertOk();
        $this->putJson('/billing/payer', ['legal_name' => 'Delayed'], $old)->assertConflict()->assertDontSee('Delayed');
        $current = $this->payerHeaders($org, $owner, $brand);
        self::assertNotSame($old['X-Payer-Revision'], $current['X-Payer-Revision']);
        $this->getJson('/billing/payer/data', $scope)->assertOk()->assertJsonPath('data.legal_name', 'New');
        $this->putJson('/billing/payer', ['legal_name' => ''], $current)->assertOk();
        // Reverting the value does not revive an earlier revision (ABA).
        $this->putJson('/billing/payer', ['legal_name' => 'Delayed'], $old)->assertConflict();
        $this->getJson('/billing/payer/data', $scope)->assertOk()->assertJsonPath('data.legal_name', '');
        self::assertSame(2, DB::table('audit_logs')->where('action', 'billing.payer.updated')->count());
    }

    public function test_payer_transport_requires_verified_https_or_named_direct_loopback_in_local_environment(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $headers = [...$this->scopeHeaders($org, $owner, $brand), 'X-CSRF-TOKEN' => csrf_token()];
        $oldEnv = app()->environment();
        $proxies = config('trustedproxy.proxies');
        try {
            app()->instance('env', 'production');
            config(['trustedproxy.proxies' => null]);
            $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20']);
            $revision = $this->getJson('https://cabinet.example.test/billing/payer/data', $headers)->assertOk()->json('revision');
            $this->putJson('https://cabinet.example.test/billing/payer', ['legal_name' => 'HTTPS'], [...$headers, 'X-Payer-Revision' => $revision])->assertOk();
            $revision = $this->getJson('https://cabinet.example.test/billing/payer/data', $headers)->assertOk()->json('revision');
            $writeHeaders = [...$headers, 'X-Payer-Revision' => $revision];

            $this->withServerVariables(['HTTPS' => 'off', 'REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20']);
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden()->assertJsonMissingPath('data');
            $this->putJson('http://souzpir.local/billing/payer', ['legal_name' => 'Denied'], $writeHeaders)->assertForbidden();
            $this->withHeader('X-Forwarded-Proto', 'https')->withHeader('X-Forwarded-For', '127.0.0.1');
            Request::setTrustedProxies(['0.0.0.0/0'], Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_FOR);
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden();
            $this->putJson('http://souzpir.local/billing/payer', ['legal_name' => 'Spoof'], $writeHeaders)->assertForbidden();

            $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden();
            app()->instance('env', 'testing');
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden();
            app()->instance('env', 'local');
            $this->getJson('http://localhost/billing/payer/data', $headers)->assertForbidden();
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertOk()->assertJsonPath('data.legal_name', 'HTTPS');
            $this->putJson('http://souzpir.local/billing/payer', ['legal_name' => 'Local'], $writeHeaders)->assertOk();
            $this->withHeader('X-Real-IP', '127.0.0.1')->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden();
            $this->flushHeaders()->withHeader('X-Forwarded-Host', 'souzpir.local')->getJson('http://other.example.test/billing/payer/data', $headers)->assertForbidden();
            $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden();
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '203.0.113.20']);
            $this->getJson('http://souzpir.local/billing/payer/data', $headers)->assertForbidden();
            self::assertSame(2, DB::table('audit_logs')->where('action', 'billing.payer.updated')->count());
        } finally {
            app()->instance('env', $oldEnv);
            config(['trustedproxy.proxies' => $proxies]);
            Request::setTrustedProxies([], 0);
            $this->flushHeaders();
        }
    }

    private function conversation(string $organization, string $brand, string $createdAt): void
    {
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    private function scopeHeaders(object $org, object $user, object $brand): array
    {
        return ['X-Billing-Context' => SubmissionScope::for(new TenantContext($org->id, $brand->id, $user->id))];
    }

    private function payerHeaders(object $org, object $user, object $brand): array
    {
        $headers = $this->scopeHeaders($org, $user, $brand);
        $revision = $this->getJson('/billing/payer/data', $headers)->assertOk()->json('revision');

        return [...$headers, 'X-Payer-Revision' => $revision];
    }
}
