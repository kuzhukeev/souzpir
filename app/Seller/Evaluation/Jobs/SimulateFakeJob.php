<?php

namespace App\Seller\Evaluation\Jobs;

use App\Seller\Evaluation\FakeSimulation;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SimulateFakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $organizationId, public string $brandId, public string $personaKey, public array $turns) {}

    public function handle(FakeSimulation $simulation): void
    {
        $simulation->run(new TenantContext($this->organizationId, $this->brandId, source: 'job'), $this->personaKey, $this->turns);
    }
}
