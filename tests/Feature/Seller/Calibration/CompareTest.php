<?php

namespace Tests\Feature\Seller\Calibration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompareTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function createRun(string $org, string $brand, array $scores): string
    {
        $persona = (string) Str::ulid();
        $run = (string) Str::ulid();
        DB::table('seller_personas')->insert(['id' => $persona, 'organization_id' => $org, 'brand_id' => $brand, 'key' => $persona, 'name' => 'Сценарий', 'type' => 'newbie', 'payload' => '{}', 'max_turns' => 5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_sim_runs')->insert(['id' => $run, 'organization_id' => $org, 'brand_id' => $brand, 'persona_id' => $persona, 'status' => 'completed', 'turn_count' => 1, 'turns' => '[]', 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'sim_run_id' => $run, 'scores' => json_encode($scores), 'issues' => '[]', 'average' => 3, 'created_at' => now(), 'updated_at' => now()]);

        return $run;
    }

    public function test_compare_aggregates_scores_and_isolates_brands(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $before = $this->createRun($org->id, $brand->id, ['goal' => 2, 'tone' => 4, 'fact' => 3]);
        $after = $this->createRun($org->id, $brand->id, ['goal' => 4, 'tone' => 3, 'fact' => 3]);
        $foreign = $this->createRun($org->id, $other->id, ['goal' => 5]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get("/seller/quality/compare?before=$before&after=$after")->assertOk()->assertInertia(fn (Assert $page) => $page->has('runs', 2)->where('comparison.improved', 1)->where('comparison.worsened', 1)->where('comparison.unchanged', 1)->where('comparison.total', 3)->has('comparison.items', 3));
        $this->get("/seller/quality/compare?before=$before&after=$foreign")->assertNotFound();
        $this->getJson('/seller/quality/compare?before=invalid&after=invalid')->assertUnprocessable();
        [$outsider] = $this->member($org, 'member', []);
        $this->actingAs($outsider)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/compare')->assertForbidden();
    }

    public function test_compares_user_selected_sets_by_scenario_and_rejects_foreign_runs(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $before = $this->createRun($org->id, $brand->id, ['goal' => 2]);
        $after = $this->createRun($org->id, $brand->id, ['goal' => 4]);
        $unmatched = $this->createRun($org->id, $brand->id, ['goal' => 3]);
        $foreign = $this->createRun($org->id, $other->id, ['goal' => 5]);
        $persona = DB::table('seller_sim_runs')->where('id', $before)->value('persona_id');
        DB::table('seller_sim_runs')->where('id', $after)->update(['persona_id' => $persona]);
        DB::table('seller_evaluations')->where('sim_run_id', $before)->update(['average' => 2]);
        DB::table('seller_evaluations')->where('sim_run_id', $after)->update(['average' => 4]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/seller/quality/compare?'.http_build_query(['before_ids' => [$before], 'after_ids' => [$after, $unmatched]]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('comparison.total', 2)->where('comparison.improved', 1)->where('comparison.items.0.delta', 2)->where('comparison.items.1.delta', null));
        $this->get('/seller/quality/compare?'.http_build_query(['before_ids' => [$before], 'after_ids' => [$foreign]]))->assertNotFound();
    }
}
