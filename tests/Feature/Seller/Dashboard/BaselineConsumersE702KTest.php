<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Evaluation\ScenarioFallbackJudge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BaselineConsumersE702KTest extends TenancyTestCase
{
    public function test_dashboard_and_calibration_exclude_test_evaluations(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = [];
        $runIds = [];
        foreach ([ScenarioFallbackJudge::COMMENT, 'Подтверждённая оценка'] as $comment) {
            $persona = (string) Str::ulid();
            $run = (string) Str::ulid();
            $id = (string) Str::ulid();
            DB::table('seller_personas')->insert(['id' => $persona, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $persona, 'name' => 'Сценарий', 'type' => 'newbie', 'payload' => '{}', 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_sim_runs')->insert(['id' => $run, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'persona_id' => $persona, 'status' => 'completed', 'turn_count' => 1, 'turns' => '[]', 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'sim_run_id' => $run, 'scores' => '{}', 'issues' => '[]', 'average' => $comment === ScenarioFallbackJudge::COMMENT ? 1 : 5, 'comment' => $comment, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
            $runIds[] = $run;
        }
        DB::table('seller_scenario_run_groups')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'status' => 'completed', 'items' => json_encode([
            ['name' => 'Тестовая', 'status' => 'completed', 'run_id' => $runIds[0]],
            ['name' => 'Настоящая', 'status' => 'completed', 'run_id' => $runIds[1]],
        ], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.quality', 5));
        $this->get('/seller/quality/calibration')->assertOk()->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $ids[1]));
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->has('days', 1)->where('days.0.count', 1)->where('days.0.average', 5)->has('testDays', 1));
        $this->post('/seller/quality/evaluations/'.$ids[0].'/disagree', ['rating' => 3, 'comment' => 'Ошибка'])->assertNotFound();
        $this->post('/seller/quality/evaluations/'.$ids[1].'/disagree', ['rating' => 3, 'comment' => 'Проверить'])->assertRedirect();
        $runPage = $this->get('/seller/quality/runs')->assertOk()->viewData('page')['props'];
        self::assertSame('fake', $runPage['groups'][0]['items'][0]['evaluation']);
        self::assertEquals(5, $runPage['groups'][0]['average']);
        self::assertSame(1, $runPage['groups'][0]['passed']);
        self::assertSame(0, $runPage['groups'][0]['failed']);
        $runs = collect($runPage['runs'])->keyBy('id');
        self::assertTrue($runs[$runIds[0]]['test_baseline']);
        self::assertFalse($runs[$runIds[1]]['test_baseline']);
        $history = collect($this->get('/seller/quality/scenarios')->assertOk()->viewData('page')['props']['runs'])->keyBy('id');
        self::assertSame('test_baseline', $history[$runIds[0]]['result']);
        self::assertSame('passed', $history[$runIds[1]]['result']);
        self::assertFalse((bool) config('features.E7-02H.defaults.fallback_enabled', false));
        $this->get('/seller/analytics')->assertOk();
        $this->getJson('/seller/analytics?from=invalid')->assertUnprocessable();
        $this->post('/seller/quality/calibration', ['evaluation_id' => $ids[0], 'rating' => 3])->assertNotFound();
        $this->post('/seller/quality/calibration', ['evaluation_id' => $ids[1], 'rating' => 6])->assertSessionHasErrors('rating');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality/calibration')->assertForbidden();
        $this->actingAs($owner)->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality/calibration')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0));
    }
}
