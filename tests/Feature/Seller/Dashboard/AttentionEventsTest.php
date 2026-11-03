<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Dashboard\AttentionEvents;
use App\Shell\Plans\Contracts\LimitValue;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\Contracts\UsageSnapshot;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AttentionEventsTest extends TenancyTestCase
{
    public function test_events_use_real_scoped_data_and_thresholds(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->app->instance(PlanLimits::class, new class implements PlanLimits
        {
            public function get(TenantContext $context, string $key): LimitValue
            {
                return new LimitValue('100', 'dialogs', true);
            }
        });
        $this->app->instance(UsageReader::class, new class implements UsageReader
        {
            public function read(TenantContext $context, UsageQuery $query): UsageSnapshot
            {
                return new UsageSnapshot(new DateTimeImmutable, ['seller.dialogs_month' => ['value' => 80, 'unit' => 'dialogs', 'status' => 'ready']]);
            }
        });
        $draft = (string) Str::ulid();
        DB::table('knowledge_releases')->insert(['id' => $draft, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'status' => 'draft', 'version' => 1, 'created_at' => now()->subDays(8), 'updated_at' => now()]);
        DB::table('kn_conditions')->insert(['id' => (string) Str::ulid(), 'release_id' => $draft, 'data' => json_encode(['min_order_rub' => 100, 'approvals' => ['min_order_rub' => false]])]);
        DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'unknown_question', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_supervisor_reports')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'report_date' => now()->toDateString(), 'scores' => '{}', 'issues' => '[{"severity":"high"},{"severity":"high","completed":true}]', 'average' => 3, 'comment' => '', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(AttentionEvents::class);
        $events = collect($service->read(new TenantContext($org->id, $brand->id), 10, 4, 10, 5))->keyBy('kind');
        foreach (['unknown', 'conditions', 'knowledge', 'conversion', 'report', 'limit'] as $kind) {
            $this->assertTrue($events->has($kind), $kind);
        }
        $this->assertSame(1, $events['report']['count']);
        $this->assertSame('−20%', $events['conversion']['detail']);
        $otherEvents = collect($service->read(new TenantContext($org->id, $other->id), 4, 0, 10, 5))->pluck('kind')->all();
        $this->assertSame(['limit'], $otherEvents);
    }

    public function test_below_thresholds_empty_brand_and_invalid_period(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->app->instance(PlanLimits::class, new class implements PlanLimits
        {
            public function get(TenantContext $context, string $key): LimitValue
            {
                return new LimitValue('100', 'dialogs', true);
            }
        });
        $this->app->instance(UsageReader::class, new class implements UsageReader
        {
            public function read(TenantContext $context, UsageQuery $query): UsageSnapshot
            {
                return new UsageSnapshot(new DateTimeImmutable, ['seller.dialogs_month' => ['value' => 79, 'unit' => 'dialogs', 'status' => 'ready']]);
            }
        });
        $this->assertSame([], app(AttentionEvents::class)->read(new TenantContext($org->id, $brand->id), 100, 81, 100, 100));
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/attention?days=0')->assertSessionHasErrors('days');
        $this->get('/seller/attention?days=abc')->assertSessionHasErrors('days');
        $this->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 0));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller/attention')->assertNotFound();
    }
}
