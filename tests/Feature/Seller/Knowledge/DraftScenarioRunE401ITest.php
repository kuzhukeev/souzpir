<?php

namespace Tests\Feature\Seller\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftScenarioRunE401ITest extends TenancyTestCase
{
    public function test_preview_records_draft_and_obeys_brand_and_permissions(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => 'preview', 'name' => 'Тест', 'type' => 'edge', 'payload' => json_encode(['first_message' => 'Привет', 'expect' => ['full_card']]), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post('/seller/knowledge/'.$draft->id.'/scenarios')->assertRedirect();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->first();
        self::assertSame($draft->id, json_decode($run->metrics, true)['knowledge_release_id']);
        self::assertFalse(json_decode($run->turns, true)[0]['meta']['expectation_checks']['full_card']);
        $this->post('/seller/knowledge/'.Str::ulid().'/scenarios')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.$draft->id.'/scenarios')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/'.$draft->id.'/scenarios')->assertNotFound();
    }
}
