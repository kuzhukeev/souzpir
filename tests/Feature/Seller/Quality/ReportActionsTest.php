<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReportActionsTest extends TenancyTestCase
{
    public function test_collect_and_checklist_are_persisted_and_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $conversation,
                'scores' => json_encode(['naturalness' => 4, 'goal' => 4, 'facts' => 4, 'objections' => 4, 'brevity' => 4]), 'issues' => json_encode([['type' => 'unknown_fact', 'layer' => 'knowledge', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Цена?', 'explanation' => 'Нет факта', 'fix' => 'Добавить цену']], JSON_UNESCAPED_UNICODE),
                'average' => 4, 'created_at' => now(), 'updated_at' => now()]);
        }
        $day = now($brand->timezone ?: 'UTC')->toDateString();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/collect')->assertRedirect('/seller/quality/'.$day)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_supervisor_reports', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'report_date' => $day]);
        $this->assertDatabaseCount('seller_supervisor_reports', 1);
        $url = '/seller/quality/'.$day.'/recommendation';
        $this->patch($url, ['fix' => 'Добавить цену', 'completed' => true])->assertRedirect();
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reports.0.issues.0.completed', true));
        $this->patch($url, ['fix' => 'Добавить цену', 'completed' => false])->assertRedirect();
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reports.0.issues.0.completed', false));
        $this->patch($url, ['fix' => '', 'completed' => true])->assertSessionHasErrors('fix');
        $this->patch($url, ['fix' => 'Чужой пункт', 'completed' => true])->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->patch($url, ['fix' => 'Добавить цену', 'completed' => true])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/quality/collect')->assertForbidden();
        $this->patch($url, ['fix' => 'Добавить цену', 'completed' => true])->assertForbidden();
    }

    public function test_collection_requires_evaluated_dialogues(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/quality/collect')->assertSessionHasErrors('report');
        $this->assertDatabaseCount('seller_supervisor_reports', 0);
    }
}
