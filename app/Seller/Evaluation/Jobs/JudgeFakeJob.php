<?php

namespace App\Seller\Evaluation\Jobs;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\Judge;
use App\Seller\Evaluation\Supervisor;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class JudgeFakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $organizationId, public string $brandId, public string $runId, public ?array $result = null) {}

    public function handle(FakeSimulation $simulation, Supervisor $supervisor): void
    {
        $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
        if ($this->result === null) {
            app(Judge::class)->evaluate($tenant, $this->runId);

            return;
        }
        $simulation->judge($tenant, $this->runId, $this->result, $supervisor);
    }
}
