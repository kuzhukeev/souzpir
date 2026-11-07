<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogEvaluationE701HTest extends TenancyTestCase
{
    public function test_dialog_links_latest_evaluation_only_with_access_and_correct_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dialog = (string) Str::ulid();
        $evaluation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'outcome' => 'full_contact', 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => $evaluation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'scores' => '{}', 'issues' => '[]', 'average' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs/'.$dialog)->assertOk()->assertInertia(fn ($page) => $page->where('evaluationId', $evaluation));
        $day = now('Europe/Moscow')->toDateString();
        $this->get('/seller/quality/'.$day)->assertOk()->assertInertia(fn ($page) => $page->where('summary.dialogs', 1)->where('summary.turns', 0)->where('summary.contact', 1)->where('summary.handoff', 0)->where('summary.other', 0));
        $this->get('/seller/quality/2026-02-31')->assertNotFound();
        $this->get('/seller/evaluations/'.$evaluation)->assertOk();
        $this->get('/seller/dialogs/not-a-ulid')->assertNotFound();
        $this->get('/seller/evaluations/'.Str::ulid())->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/dialogs/'.$dialog)->assertNotFound();
        $this->get('/seller/evaluations/'.$evaluation)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/'.$dialog)->assertOk()->assertInertia(fn ($page) => $page->where('evaluationId', null));
        $this->get('/seller/evaluations/'.$evaluation)->assertForbidden();
    }
}
