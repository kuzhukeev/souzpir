<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReleaseContinuationTest extends TenancyTestCase
{
    public function test_checks_are_conservative_and_run_is_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['ok', ['one_question_per_turn']], ['unknown', ['full_card']]] as [$key, $expect]) {
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $key, 'name' => $key, 'type' => 'edge', 'payload' => json_encode(['first_message' => 'Привет', 'expect' => $expect]), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post('/seller/knowledge/'.$draft->id.'/scenarios')->assertRedirect();
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('scenarioRun.passed', 0)->where('scenarioRun.failed', 2));
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->first();
        $this->get('/seller/knowledge/runs/'.$run->id)->assertOk()->assertInertia(fn ($page) => $page->where('run.id', $run->id));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/runs/'.$run->id)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/knowledge/runs/'.$run->id)->assertNotFound();
    }

    public function test_rollback_protects_existing_draft_and_creates_new_one_without_switching_active_release(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->post('/seller/knowledge/'.$draft->id.'/publish', ['version' => 1])->assertRedirect();
        $first = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        $this->post('/seller/knowledge/'.$draft->id.'/publish', ['version' => 2])->assertRedirect();
        $active = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        $this->post('/seller/knowledge/'.$first.'/rollback')->assertSessionHasErrors('rollback');
        $this->assertDatabaseHas('knowledge_releases', ['id' => $draft->id, 'status' => 'draft', 'version' => 3]);
        $this->assertSame($active, DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id'));
        DB::table('knowledge_releases')->where('id', $draft->id)->update(['status' => 'building']);
        $this->post('/seller/knowledge/'.$first.'/rollback')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('knowledge_releases', ['brand_id' => $brand->id, 'status' => 'draft', 'version' => 1]);
        $this->assertSame($active, DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/'.$first.'/rollback')->assertNotFound();
    }
}
