<?php

namespace Tests\Feature\Shell\Plans;

use App\Providers\Features\FeatureRegistry;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageContributor;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\LimitEnforcer;
use App\Shell\Plans\RegisteredUsageReader;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private string $org;

    private string $other;

    private string $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = (string) Str::ulid();
        $this->other = (string) Str::ulid();
        $this->plan = (string) Str::ulid();
        foreach ([$this->org, $this->other] as $id) {
            DB::table('organizations')->insert(['id' => $id, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        }
        DB::table('plans')->insert(['id' => $this->plan, 'code' => 'test', 'name' => 'Test', 'is_public' => false]);
    }

    private function assign(string $org, string $starts, ?string $ends = null): void
    {
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'plan_id' => $this->plan, 'starts_at' => $starts, 'ends_at' => $ends]);
    }

    private function limit(string $key, ?string $value, string $unit = 'шт.'): void
    {
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $this->plan, 'key' => $key, 'value' => $value, 'unit' => $unit]);
    }

    public function test_unset_and_zero_are_distinct_and_organization_isolation_holds(): void
    {
        $this->assign($this->org, '2026-01-01 00:00:00+00');
        $this->limit('users.max', null);
        $this->limit('brands.max', '0');
        $service = app(PlanLimits::class);
        $ctx = new TenantContext($this->org);
        self::assertFalse($service->get($ctx, 'users.max')->configured);
        self::assertNull($service->get($ctx, 'users.max')->value);
        self::assertFalse($service->get(new TenantContext($this->other), 'brands.max')->configured);
        $zero = $service->get($ctx, 'brands.max');
        self::assertTrue($zero->configured);
        self::assertSame('0.0000', $zero->value);
        self::assertSame('шт.', $zero->unit);
    }

    public function test_active_assignment_uses_half_open_time_interval_and_decimal_strings(): void
    {
        $this->assign($this->org, '2026-01-01 00:00:00+00', '2026-02-01 00:00:00+00');
        $this->limit('ai.units_month', '123456789.1234', 'ед.');
        $ctx = new TenantContext($this->org);
        $this->travelTo(now()->parse('2026-01-15 00:00:00+00'));
        self::assertSame('123456789.1234', app(PlanLimits::class)->get($ctx, 'ai.units_month')->value);
        $this->travelTo(now()->parse('2026-02-01 00:00:00+00'));
        self::assertFalse(app(PlanLimits::class)->get($ctx, 'ai.units_month')->configured);
    }

    public function test_overlapping_assignments_are_rejected_by_database(): void
    {
        $this->assign($this->org, '2026-01-01 00:00:00+00', '2026-03-01 00:00:00+00');
        try {
            DB::transaction(fn () => $this->assign($this->org, '2026-02-01 00:00:00+00', null));
            self::fail('Overlapping assignment accepted');
        } catch (QueryException $error) {
            self::assertStringContainsString('organization_plan_no_overlap', $error->getMessage());
        }
        $this->assign($this->org, '2026-03-01 00:00:00+00', null);
        self::assertSame(2, DB::table('organization_plan')->where('organization_id', $this->org)->count());
    }

    public function test_enforcement_returns_422_only_when_configured_limit_is_exceeded(): void
    {
        $this->assign($this->org, '2026-01-01 00:00:00+00');
        $this->limit('brands.max', '2');
        $id = $this->org;
        Route::post('/test-plan-limit', function () use ($id) {
            app(LimitEnforcer::class)->assertWithin(new TenantContext($id), 'brands.max', request()->string('count')->toString());

            return response()->json(['ok' => true]);
        });
        $this->postJson('/test-plan-limit', ['count' => '2'])->assertOk();
        $this->postJson('/test-plan-limit', ['count' => '2.0001'])->assertUnprocessable()->assertJsonValidationErrors('brands.max');
        app(LimitEnforcer::class)->assertWithin(new TenantContext($this->other), 'brands.max', '9999999999999999999999');
    }

    public function test_missing_usage_source_is_unavailable_not_zero(): void
    {
        $query = new UsageQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'), ['seller.dialogues']);
        $snapshot = app(UsageReader::class)->read(new TenantContext($this->org), $query);
        self::assertSame(['value' => null, 'unit' => '', 'status' => 'unavailable'], $snapshot->metrics['seller.dialogues']);
    }

    public function test_registered_usage_preserves_measured_zero_and_unavailable_metrics(): void
    {
        $registry = $this->createMock(FeatureRegistry::class);
        $registry->method('extensions')->willReturn(['usage.test' => FixtureUsageContributor::class]);
        $reader = new RegisteredUsageReader($registry);
        $query = new UsageQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'), ['seller.dialogues', 'ai.calls']);
        $metrics = $reader->read(new TenantContext($this->org), $query)->metrics;
        self::assertSame(['value' => 0, 'unit' => 'шт.', 'status' => 'ready'], $metrics['seller.dialogues']);
        self::assertSame(['value' => null, 'unit' => '', 'status' => 'unavailable'], $metrics['ai.calls']);
    }

    public function test_duplicate_usage_sources_fail_closed(): void
    {
        $registry = $this->createMock(FeatureRegistry::class);
        $registry->method('extensions')->willReturn(['usage.first' => FixtureUsageContributor::class, 'usage.second' => FixtureUsageContributor::class]);
        $reader = new RegisteredUsageReader($registry);
        $query = new UsageQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'), ['seller.dialogues']);
        $this->expectException(\LogicException::class);
        $reader->read(new TenantContext($this->org), $query);
    }

    public function test_unresolved_tenant_fails_closed(): void
    {
        $this->expectException(\LogicException::class);
        app(PlanLimits::class)->get(new TenantContext, 'users.max');
    }
}

final class FixtureUsageContributor implements UsageContributor
{
    public function read(TenantContext $context, UsageQuery $query): array
    {
        return ['seller.dialogues' => ['value' => 0, 'unit' => 'шт.', 'status' => 'ready']];
    }
}
