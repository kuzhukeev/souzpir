<?php

namespace App\Seller\Evaluation\Jobs;

use App\ProtectedOperations\SellerSimulation\ProtectedSimulationRuntime;
use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\RuntimeSimulation;
use App\Seller\Evaluation\SimulationRuntime;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;

final class SimulateFakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $organizationId, public string $brandId, public string $personaKey, public array $turns) {}

    public function handle(FakeSimulation $simulation): void
    {
        $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
        if (config('features.E3-06D.defaults.runtime_enabled', true) === false) {
            $simulation->run($tenant, $this->personaKey, $this->turns);

            return;
        }
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Protected fake simulation runtime is unavailable.');
        }
        app(TenantContextRunner::class)->run($tenant, fn () => app(RuntimeSimulation::class)->run(
            $tenant, $this->personaKey, count($this->turns), app()->bound(SimulationRuntime::class)
            ? app(SimulationRuntime::class) : app(ProtectedSimulationRuntime::class)
        ));
    }
}
