<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LegacyReportLinksE701JTest extends TenancyTestCase
{
    public function test_legacy_link_requires_a_confirmed_reference_and_brand_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dialog = (string) Str::ulid();
        $issue = ['type' => 'unknown_fact', 'layer' => 'knowledge', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Вопрос', 'explanation' => 'Нет факта', 'fix' => 'Добавить факт'];
        $day = now('Europe/Moscow')->toDateString();
        $this->evaluation($org->id, $brand->id, $dialog, $issue);
        $evaluation = DB::table('seller_evaluations')->where('conversation_id', $dialog)->value('id');
        $report = (string) Str::ulid();
        DB::table('seller_supervisor_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'report_date' => $day, 'scores' => '{}', 'issues' => json_encode([[...$issue, 'evaluation_id' => $evaluation]]), 'average' => 4, 'comment' => 'Доклад', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reports.0.issues.0.conversation_id', $dialog));
        $this->get('/seller/dialogs/'.$dialog.'?report_day='.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reportDay', $day));
        $this->post('/seller/quality/evaluations/'.$evaluation.'/disagree', ['rating' => 3, 'comment' => 'Нужно уточнение'])->assertRedirect();
        $this->assertDatabaseHas('judge_calibrations', ['evaluation_id' => $evaluation, 'rating' => 3, 'user_id' => $owner->id]);
        $this->postJson('/seller/quality/evaluations/'.Str::ulid().'/disagree', ['rating' => 0, 'comment' => ''])->assertUnprocessable();
        $this->postJson('/seller/quality/evaluations/'.Str::ulid().'/disagree', ['rating' => 6, 'comment' => 'Нет'])->assertUnprocessable();
        $this->evaluation($org->id, $brand->id, (string) Str::ulid(), $issue);
        DB::table('seller_supervisor_reports')->where('id', $report)->update(['issues' => json_encode([$issue])]);
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->missing('reports.0.issues.0.conversation_id'));
        $this->get('/seller/dialogs/'.$dialog.'?report_day='.$day)->assertOk()->assertInertia(fn ($page) => $page->where('reportDay', null));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/dialogs/'.$dialog.'?report_day='.$day)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality/'.$day)->assertForbidden();
    }

    private function evaluation(string $org, string $brand, string $dialog, array $issue): void
    {
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org, 'brand_id' => $brand, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $dialog, 'scores' => '{}', 'issues' => json_encode([$issue]), 'average' => 4, 'created_at' => now(), 'updated_at' => now()]);
    }
}
