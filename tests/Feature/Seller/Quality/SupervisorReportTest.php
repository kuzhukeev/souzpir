<?php

namespace Tests\Feature\Seller\Quality;

use App\Seller\Quality\SupervisorReportWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SupervisorReportTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function reportData(): array
    {
        return ['naturalness' => 4, 'goal' => 5, 'facts' => 3, 'objections' => 4, 'brevity' => 5, 'comment' => 'Проверка',
            'issues' => [['type' => 'unknown_fact', 'layer' => 'knowledge', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Вопрос', 'explanation' => 'Нет факта', 'fix' => 'Добавить факт']]];
    }

    public function test_report_is_separate_from_evaluations_and_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $day = now('UTC')->subDay()->toDateString(); // inside the 7-day window in any brand timezone
        $id = app(SupervisorReportWriter::class)->write($tenant, $day, $this->reportData(), 1);
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'scores' => '{}', 'issues' => '[]', 'average' => 3, 'created_at' => $day.' 12:00:00+00', 'updated_at' => now()]);
        $this->assertDatabaseHas('seller_supervisor_reports', ['id' => $id, 'brand_id' => $brand->id]);
        $this->assertDatabaseCount('seller_evaluations', 1);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->has('reportDays', 1)->where('reportDays.0.count', 1));
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->has('reports', 1)->where('reports.0.source', 'supervisor')->where('reports.0.issues.0.layer', 'knowledge')->has('evaluations', 1));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->has('reportDays', 0)->has('days', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality')->assertForbidden();
    }

    public function test_invalid_report_is_rejected(): void
    {
        [, $org, $brand] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        try {
            app(SupervisorReportWriter::class)->write($tenant, '2026-02-30', $this->reportData(), 1);
            $this->fail('Invalid date accepted');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('seller_supervisor_reports', 0);
        }
        $bad = $this->reportData();
        $bad['facts'] = 6;
        try {
            app(SupervisorReportWriter::class)->write($tenant, '2026-11-02', $bad, 1);
            $this->fail('Invalid score accepted');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('seller_supervisor_reports', 0);
        }
    }
}
