<?php

namespace App\Seller\Evaluation\Jobs;

use App\Seller\Evaluation\FakeSimulation;
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

    public function __construct(public string $organizationId, public string $brandId, public string $runId, public array $result) {}

    public function handle(FakeSimulation $simulation, Supervisor $supervisor): void
    {
        $simulation->judge(new TenantContext($this->organizationId, $this->brandId, source: 'job'), $this->runId, $this->result, $supervisor);
    }
}
