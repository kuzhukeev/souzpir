<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Scenarios\RunScenarioGroup;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RunScenarioGroupTest extends TenancyTestCase
{
    public function test_group_shows_failure_when_a_scenario_disappears(): void
    {
        [, $org, $brand] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake']);
        $group = (string) Str::ulid();
        DB::table('seller_scenario_run_groups')->insert(['id' => $group, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'status' => 'queued', 'items' => json_encode([['key' => 'missing', 'name' => 'Missing', 'max_turns' => 1, 'status' => 'queued']]), 'created_at' => now(), 'updated_at' => now()]);
        (new RunScenarioGroup($org->id, $brand->id, $group))->handle(app(FakeSimulation::class));
        $row = DB::table('seller_scenario_run_groups')->where('id', $group)->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('failed', json_decode($row->items, true)[0]['status']);
    }

    public function test_group_records_results_and_ignores_a_different_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake']);
        $items = [];
        foreach (['first', 'second'] as $key) {
            $id = (string) Str::ulid();
            DB::table('seller_personas')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'key' => $key, 'name' => $key, 'type' => 'newbie', 'payload' => json_encode(['expect' => []]), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $items[] = ['id' => $id, 'key' => $key, 'name' => $key, 'max_turns' => 1, 'status' => 'queued'];
        }
        $group = (string) Str::ulid();
        DB::table('seller_scenario_run_groups')->insert(['id' => $group, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'status' => 'queued', 'items' => json_encode($items), 'created_at' => now(), 'updated_at' => now()]);
        app()->instance(SimulationRuntime::class, new class implements SimulationRuntime
        {
            public function open(TenantContext $context, string $key): array
            {
                return ['conversation_id' => (string) Str::ulid(), 'agent' => 'Hello'];
            }

            public function turn(TenantContext $context, string $conversationId, string $client, string $key): array
            {
                return ['agent' => 'Hello'];
            }
        });
        (new RunScenarioGroup($org->id, $other->id, $group))->handle(app(FakeSimulation::class));
        $this->assertDatabaseHas('seller_scenario_run_groups', ['id' => $group, 'status' => 'queued']);
        (new RunScenarioGroup($org->id, $brand->id, $group))->handle(app(FakeSimulation::class));
        $row = DB::table('seller_scenario_run_groups')->where('id', $group)->first();
        $this->assertSame('completed', $row->status);
        $results = json_decode($row->items, true);
        $this->assertCount(2, $results);
        $this->assertSame(['completed', 'completed'], array_column($results, 'status'));
        $this->assertSame(['unavailable', 'unavailable'], array_column($results, 'evaluation'));
    }
}
