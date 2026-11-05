<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\ScenarioFallbackJudge;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Scenarios\RunScenarioGroup;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FallbackJudgeE702HTest extends TenancyTestCase
{
    public function test_group_fallback_is_fake_deterministic_and_brand_scoped(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake', 'features.E7-02H.defaults.fallback_enabled' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $items = [];
        foreach (['first', 'second'] as $key) {
            $id = (string) Str::ulid();
            DB::table('seller_personas')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $key, 'name' => $key, 'type' => 'newbie', 'payload' => json_encode(['expect' => []]), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $items[] = ['id' => $id, 'key' => $key, 'name' => $key, 'max_turns' => 1, 'status' => 'queued'];
        }
        $group = (string) Str::ulid();
        DB::table('seller_scenario_run_groups')->insert(['id' => $group, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'status' => 'queued', 'items' => json_encode($items), 'created_at' => now(), 'updated_at' => now()]);
        app()->instance(SimulationRuntime::class, new class implements SimulationRuntime
        {
            public function open(TenantContext $context, string $key): array
            {
                return ['conversation_id' => (string) Str::ulid(), 'agent' => 'Тестовый ответ'];
            }

            public function turn(TenantContext $context, string $conversationId, string $client, string $key): array
            {
                return ['agent' => 'Тестовый ответ'];
            }
        });
        (new RunScenarioGroup($org->id, $other->id, $group))->handle(app(FakeSimulation::class));
        $this->assertDatabaseHas('seller_scenario_run_groups', ['id' => $group, 'status' => 'queued']);
        (new RunScenarioGroup($org->id, $brand->id, $group))->handle(app(FakeSimulation::class));
        $row = DB::table('seller_scenario_run_groups')->where('id', $group)->sole();
        $items = json_decode($row->items, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['fake', 'fake'], array_column($items, 'evaluation'));
        self::assertSame(2, DB::table('seller_evaluations')->where('brand_id', $brand->id)->count());
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $first = app(ScenarioFallbackJudge::class)->evaluate($tenant, $items[0]['run_id']);
        self::assertSame($first, app(ScenarioFallbackJudge::class)->evaluate($tenant, $items[0]['run_id']));
        try {
            app(ScenarioFallbackJudge::class)->evaluate(new TenantContext($org->id, $other->id, source: 'job'), $items[0]['run_id']);
            self::fail('Foreign run accepted.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->where('groups.0.average', 3)->where('groups.0.passed', 0)->where('groups.0.failed', 0)->where('groups.0.items.0.evaluation', 'fake'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality/runs')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->has('groups', 0));
        Http::assertNothingSent();
    }

    public function test_non_fake_driver_is_rejected(): void
    {
        [, $org, $brand] = $this->cabinet();
        config(['runtime.ai_driver' => 'live']);
        $this->expectException(LogicException::class);
        app(ScenarioFallbackJudge::class)->evaluate(new TenantContext($org->id, $brand->id, source: 'job'), (string) Str::ulid());
    }
}
