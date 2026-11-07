<?php

namespace App\Seller\Evaluation\Jobs;

use App\ProtectedOperations\SellerSimulation\ProtectedSimulationRuntime;
use App\Seller\Evaluation\DraftScenarioPreview;
use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\RuntimeSimulation;
use App\Seller\Evaluation\ScenarioTranscript;
use App\Seller\Evaluation\SimulationKnowledgeRelease;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SimulateFakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $organizationId, public string $brandId, public string $personaKey, public array $turns, public string $clientModel = 'fake') {}

    public function handle(FakeSimulation $simulation): string
    {
        $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
        if (config('features.E3-06D.defaults.runtime_enabled', true) === false) {
            return $simulation->run($tenant, $this->personaKey, $this->turns);
        }
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Protected fake simulation runtime is unavailable.');
        }

        $persona = DB::table('seller_personas')->where('organization_id', $this->organizationId)
            ->where('brand_id', $this->brandId)->where('key', $this->personaKey)->first(['payload', 'max_turns']);
        $payload = $persona ? json_decode($persona->payload, true) : null;
        if (is_array($payload) && app(ScenarioTranscript::class)->clients($payload) !== []) {
            return app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $payload, $persona, $simulation): string {
                $release = app(SimulationKnowledgeRelease::class)->id($tenant);
                if (! is_string($release)) {
                    throw new LogicException('Published knowledge is unavailable.');
                }
                $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $release, true);
                $turns = app(DraftScenarioPreview::class)->dialogue($snapshot, $payload, min(count($this->turns), (int) $persona->max_turns), null, $this->personaKey, $this->clientModel);

                return $simulation->run($tenant, $this->personaKey, $turns);
            });
        }

        return app(TenantContextRunner::class)->run($tenant, fn () => app(RuntimeSimulation::class)->run(
            $tenant, $this->personaKey, count($this->turns), app()->bound(SimulationRuntime::class)
            ? app(SimulationRuntime::class) : app(ProtectedSimulationRuntime::class), $this->clientModel
        ));
    }
}
