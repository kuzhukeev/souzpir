<?php

namespace Tests\Feature\Seller\Quality;

use App\Seller\Quality\SupervisorReportWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class QualityOverviewTest extends TenancyTestCase
{
    public function test_overview_aggregates_real_reports_for_the_current_brand_only(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $result = ['naturalness' => 4, 'goal' => 4, 'facts' => 4, 'objections' => 4, 'brevity' => 4, 'comment' => 'Проверка', 'issues' => [['type' => 'unknown_fact', 'layer' => 'knowledge', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Цена?', 'explanation' => 'Нет факта', 'fix' => 'Добавить факт']]];
        app(SupervisorReportWriter::class)->write(new TenantContext($org->id, $brand->id, source: 'job'), now()->toDateString(), $result, 1);
        app(SupervisorReportWriter::class)->write(new TenantContext($org->id, $other->id, source: 'job'), now()->toDateString(), $result, 1);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->has('reportDays', 1)->where('reportDays.0.count', 1)->where('reportDays.0.withIssues', 1)->where('reportDays.0.medium', 1)->where('reportDays.0.topIssues.0', 'unknown_fact'));
        $this->get('/seller/quality/'.now()->toDateString())->assertOk()->assertInertia(fn ($page) => $page->has('reports', 1)->where('reports.0.issues.0.quote', 'Цена?'));
        $this->get('/seller/quality/2026-02-30')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality')->assertForbidden();
        $this->get('/seller/quality/'.now()->toDateString())->assertForbidden();
    }
}
