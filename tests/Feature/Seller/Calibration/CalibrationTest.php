<?php

namespace Tests\Feature\Seller\Calibration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CalibrationTest extends TenancyTestCase
{
    private function evaluatedRun(string $org, string $brand): string
    {
        $persona = (string) Str::ulid();
        $run = (string) Str::ulid();
        $evaluation = (string) Str::ulid();
        DB::table('seller_personas')->insert(['id' => $persona, 'organization_id' => $org, 'brand_id' => $brand, 'key' => $persona, 'name' => 'Сценарий', 'type' => 'newbie', 'payload' => '{}', 'max_turns' => 5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_sim_runs')->insert(['id' => $run, 'organization_id' => $org, 'brand_id' => $brand, 'persona_id' => $persona, 'status' => 'completed', 'turn_count' => 1, 'turns' => '[]', 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => $evaluation, 'organization_id' => $org, 'brand_id' => $brand, 'sim_run_id' => $run, 'scores' => '{}', 'issues' => '[]', 'average' => 3.5, 'created_at' => now(), 'updated_at' => now()]);

        return $evaluation;
    }

    public function test_calibration_saved_with_judge_comparison_and_brand_isolation(): void
    {
        [$owner,$org,$brand,$other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = $this->evaluatedRun($org->id, $brand->id);
        $foreign = $this->evaluatedRun($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/calibration', ['evaluation_id' => $id, 'rating' => 5, 'comment' => 'Ответ полезен'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/quality/calibration')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.judge', 3.5)->where('items.0.rating', 5));
        $this->post('/seller/quality/calibration', ['evaluation_id' => $foreign, 'rating' => 4])->assertNotFound();
        $this->post('/seller/quality/calibration', ['evaluation_id' => $id, 'rating' => 6])->assertSessionHasErrors('rating');
        $this->post('/seller/quality/calibration', ['evaluation_id' => 'invalid', 'rating' => 3])->assertSessionHasErrors('evaluation_id');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/calibration')->assertForbidden();
        $this->post('/seller/quality/calibration', ['evaluation_id' => $id, 'rating' => 3])->assertForbidden();
    }

    public function test_batch_calibration_checks_brand_permissions_and_input(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $first = $this->evaluatedRun($org->id, $brand->id);
        $second = $this->evaluatedRun($org->id, $brand->id);
        $foreign = $this->evaluatedRun($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $items = [['evaluation_id' => $first, 'rating' => 5, 'comment' => 'Хорошо'], ['evaluation_id' => $second, 'rating' => 2, 'comment' => 'Нужно исправить']];
        $this->post('/seller/quality/calibration', ['items' => $items])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('judge_calibrations', ['evaluation_id' => $first, 'rating' => 5]);
        $this->assertDatabaseHas('judge_calibrations', ['evaluation_id' => $second, 'rating' => 2]);
        $this->post('/seller/quality/calibration', ['items' => [['evaluation_id' => $foreign, 'rating' => 3]]])->assertNotFound();
        $this->post('/seller/quality/calibration', ['items' => [['evaluation_id' => $first, 'rating' => 7]]])->assertSessionHasErrors('items.0.rating');
        $this->post('/seller/quality/calibration', ['items' => []])->assertSessionHasErrors('items');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/calibration', ['items' => $items])->assertForbidden();
    }
}
