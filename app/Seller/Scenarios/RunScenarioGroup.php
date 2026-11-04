<?php

namespace App\Seller\Scenarios;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Evaluation\Judge;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RunScenarioGroup implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public string $brandId, public string $groupId) {}

    public function handle(FakeSimulation $simulation): void
    {
        $query = DB::table('seller_scenario_run_groups')->where('id', $this->groupId)
            ->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId);
        $group = $query->first();
        if (! $group) {
            return;
        }
        $items = json_decode($group->items, true, flags: JSON_THROW_ON_ERROR);
        $query->update(['status' => 'processing', 'updated_at' => now()]);
        foreach ($items as &$item) {
            try {
                $runId = (new SimulateFakeJob($this->organizationId, $this->brandId, $item['key'], array_fill(0, $item['max_turns'], [])))->handle($simulation);
                $item['run_id'] = $runId;
                $item['status'] = DB::table('seller_sim_runs')->where('id', $runId)->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)->value('status') ?? 'failed';
                if ($item['status'] === 'completed') {
                    $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
                    try {
                        app(TenantContextRunner::class)->run($tenant, fn () => app(Judge::class)->evaluate($tenant, $runId));
                    } catch (Throwable $error) {
                        report($error);
                        $item['evaluation'] = 'unavailable';
                    }
                }
            } catch (Throwable $error) {
                report($error);
                $item['status'] = 'failed';
            }
            $query->update(['items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        unset($item);
        $query->update(['status' => collect($items)->contains('status', 'failed') ? 'failed' : 'completed', 'updated_at' => now()]);
    }
}
