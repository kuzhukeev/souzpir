<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class QualityTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function evaluation(string $org, string $brand): void
    {
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
            'scores' => json_encode(['naturalness' => 4, 'goal' => 5]), 'issues' => json_encode([['type' => 'unknown_fact', 'layer' => 'knowledge', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Цена?', 'explanation' => 'Нет факта', 'fix' => 'Добавить цену']], JSON_UNESCAPED_UNICODE),
            'average' => 4.5, 'comment' => 'Проверить', 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => '2026-11-02 12:00:00+00']);
    }

    public function test_daily_evaluation_list_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->evaluation($org->id, $brand->id);
        $this->evaluation($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->has('days', 1)->where('days.0.count', 1)->where('days.0.issues', 1));
        $this->get('/seller/quality/2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 1)->where('evaluations.0.issues.0.type', 'unknown_fact')->where('evaluations.0.scores.naturalness', 4));
        $this->get('/seller/quality/2026-10-30')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 0));
        $this->get('/seller/quality/2026-02-30')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->where('days.0.count', 1));
    }

    public function test_quality_requires_permission_and_enabled_module(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality')->assertForbidden();
        $this->get('/seller/quality/2026-11-02')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller/quality')->assertNotFound();
    }
}
