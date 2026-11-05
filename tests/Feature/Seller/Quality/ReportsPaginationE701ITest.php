<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReportsPaginationE701ITest extends TenancyTestCase
{
    public function test_report_issue_keeps_dialog_link_for_authorized_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dialog = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        $issue = ['type' => 'unknown_fact', 'layer' => 'knowledge', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Вопрос', 'explanation' => 'Нет факта', 'fix' => 'Добавить факт'];
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'scores' => json_encode(['naturalness' => 4, 'goal' => 4, 'facts' => 4, 'objections' => 4, 'brevity' => 4]), 'issues' => json_encode([$issue]), 'average' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $day = now('Europe/Moscow')->toDateString();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/collect')->assertRedirect();
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reports.0.issues.0.conversation_id', $dialog));
        $this->get('/seller/dialogs/'.$dialog.'?report_day='.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reportDay', $day));
        $this->get('/seller/dialogs/'.$dialog.'?report_day=invalid')->assertSessionHasErrors('report_day');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/dialogs/'.$dialog.'?report_day='.$day)->assertNotFound();
    }

    public function test_report_days_are_paginated_per_brand_and_validated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        for ($i = 0; $i < 12; $i++) {
            DB::table('seller_supervisor_reports')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'report_date' => now('Europe/Moscow')->subDays($i)->toDateString(), 'scores' => '{}', 'issues' => '[]', 'average' => 4, 'comment' => 'Доклад', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality?period=30&page=1')->assertOk()->assertInertia(fn ($page) => $page->has('reportDays', 12)->has('reportPageDays', 10)->where('pagination.pages', 2)->where('pagination.total', 12));
        $this->get('/seller/quality?period=30&page=2')->assertOk()->assertInertia(fn ($page) => $page->has('reportPageDays', 2)->where('pagination.page', 2));
        $this->getJson('/seller/quality?period=3')->assertUnprocessable();
        $this->getJson('/seller/quality?page=0')->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality?period=30')->assertOk()->assertInertia(fn ($page) => $page->has('reportPageDays', 0)->where('pagination.total', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality')->assertForbidden();
    }
}
