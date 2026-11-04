<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EvaluationPageE701GTest extends TenancyTestCase
{
    public function test_html_and_json_evaluation_respect_brand_and_permissions(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::ulid();
        DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'scores' => json_encode(['naturalness' => 4]), 'issues' => '[]', 'average' => 4, 'comment' => 'Пример', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/evaluations/'.$id)->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Quality/Evaluation')->where('evaluation.id', $id)->where('evaluation.scores.naturalness', 4));
        $this->getJson('/seller/evaluations/'.$id)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.average', 4);
        $this->get('/seller/evaluations/not-an-id')->assertNotFound();
        $this->get('/seller/evaluations/'.Str::ulid())->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/evaluations/'.$id)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/evaluations/'.$id)->assertForbidden();
        $this->getJson('/seller/evaluations/'.$id)->assertForbidden();
    }
}
