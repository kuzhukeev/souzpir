<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Scenarios\RunScenarioGroup;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClientModelGroupE702NTest extends TenancyTestCase
{
    public function test_group_records_results_and_ignores_a_different_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake', 'features.E7-02N.defaults.client_models' => ['behavior' => ['label' => 'Поведение', 'strategy' => 'behavior']]]);
        $items = [];
        foreach (['first', 'second'] as $key) {
            $id = (string) Str::ulid();
            DB::table('seller_personas')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'key' => $key, 'name' => $key, 'type' => 'newbie', 'payload' => json_encode(['expect' => [], 'behavior' => 'Особый запрос', 'facts' => ['city' => 'Москва']]), 'max_turns' => 2, 'created_at' => now(), 'updated_at' => now()]);
            $items[] = ['id' => $id, 'key' => $key, 'name' => $key, 'max_turns' => 2, 'status' => 'queued', 'client_model' => 'behavior'];
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
        $run = DB::table('seller_sim_runs')->where('id', $results[0]['run_id'])->first();
        $this->assertSame('behavior', json_decode($run->metrics, true)['client_model']);
        $this->assertSame('Особый запрос', json_decode($run->turns, true)[1]['client']);
    }
}
