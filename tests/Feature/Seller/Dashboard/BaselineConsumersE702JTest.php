<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Evaluation\ScenarioFallbackJudge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BaselineConsumersE702JTest extends TenancyTestCase
{
    public function test_dashboard_and_calibration_exclude_test_evaluations(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = [];
        foreach ([ScenarioFallbackJudge::COMMENT, 'Подтверждённая оценка'] as $comment) {
            $persona = (string) Str::ulid();
            $run = (string) Str::ulid();
            $id = (string) Str::ulid();
            DB::table('seller_personas')->insert(['id' => $persona, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $persona, 'name' => 'Сценарий', 'type' => 'newbie', 'payload' => '{}', 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_sim_runs')->insert(['id' => $run, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'persona_id' => $persona, 'status' => 'completed', 'turn_count' => 1, 'turns' => '[]', 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'sim_run_id' => $run, 'scores' => '{}', 'issues' => '[]', 'average' => $comment === ScenarioFallbackJudge::COMMENT ? 1 : 5, 'comment' => $comment, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.quality', 5));
        $this->get('/seller/quality/calibration')->assertOk()->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $ids[1]));
        $this->post('/seller/quality/calibration', ['evaluation_id' => $ids[0], 'rating' => 3])->assertNotFound();
        $this->post('/seller/quality/calibration', ['evaluation_id' => $ids[1], 'rating' => 6])->assertSessionHasErrors('rating');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality/calibration')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/quality/calibration')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0));
    }
}
