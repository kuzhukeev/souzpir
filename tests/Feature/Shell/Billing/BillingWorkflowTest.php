<?php

namespace Tests\Feature\Shell\Billing;

use App\Shell\Audit\Contracts\AuditWriter;
use Illuminate\Database\QueryException;
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
            ->where('usage.0.status', 'ready')->where('usage.1.value', null)->where('usage.1.status', 'unavailable')
            ->where('usage.2.value', null)->where('usage.3.value', null)->where('period.from', '2026-10-01')->where('period.to', '2026-10-31'));
        $this->withSession(['brand_id' => $other->id])->get('/billing/usage?from=2026-10-01&to=2026-10-31')->assertOk()
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
            ->where('requestEnabled', false));
    }

    public function test_payer_is_owner_only_validated_audited_and_preserves_other_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        DB::table('organizations')->where('id', $org->id)->update(['settings' => json_encode(['other' => ['keep' => true]], JSON_THROW_ON_ERROR)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/billing/payer', ['legal_name' => 'Компания', 'inn' => '123', 'kpp' => 'bad', 'email' => 'bad'])
            ->assertSessionHasErrors(['inn', 'kpp', 'email'])->assertSessionMissing('_old_input.inn');
        $this->put('/billing/payer', ['legal_name' => 'Компания', 'inn' => '1234567890', 'kpp' => '123456789', 'address' => 'Тестовый адрес', 'email' => 'billing@example.test'])
            ->assertRedirect('/billing/payer')->assertSessionHasNoErrors();
        $settings = json_decode(DB::table('organizations')->where('id', $org->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($settings['other']['keep']);
        self::assertSame('1234567890', $settings['payer']['inn']);
        $audit = DB::table('audit_logs')->where('action', 'billing.payer.updated')->sole();
        self::assertStringNotContainsString('1234567890', json_encode($audit, JSON_THROW_ON_ERROR));
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payer', null)->where('canManage', false));
        $this->get('/billing/payer')->assertForbidden();
        $this->put('/billing/payer', ['legal_name' => 'Нет'])->assertForbidden();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/billing')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payer', null)->where('canManage', false));
        $this->get('/billing/plans')->assertForbidden();
    }

    public function test_payer_audit_failure_rolls_back_and_request_is_not_faked(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $audit = \Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        try {
            $this->put('/billing/payer', ['legal_name' => 'Не сохранять']);
            self::fail('Audit failure must roll back payer.');
        } catch (\RuntimeException $error) {
            self::assertSame('Не удалось сохранить реквизиты.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        $settings = json_decode(DB::table('organizations')->where('id', $org->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('payer', $settings);
        $this->withExceptionHandling()->post('/billing/plans/request', ['plan_id' => (string) Str::ulid()])->assertStatus(503);
        $this->assertDatabaseCount('tickets', 0);
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_sql_failure_does_not_leak_payer_bindings_or_trace(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withoutExceptionHandling();
        DB::listen(static function ($event): void {
            if (str_starts_with(strtolower($event->sql), 'update "organizations"')) {
                throw new QueryException('pgsql', $event->sql, $event->bindings, new \RuntimeException('fixture database failure'));
            }
        });
        try {
            $this->put('/billing/payer', ['inn' => '1234567890', 'address' => 'PRIVATE-ADDRESS-MARKER']);
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
            ->assertInertia(fn (Assert $page) => $page->where('payer', null)->where('canManage', false));
        $this->get('/billing/payer')->assertForbidden();
    }

    private function conversation(string $organization, string $brand, string $createdAt): void
    {
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }
}
