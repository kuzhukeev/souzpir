<?php

namespace App\Seller\Evaluation\Jobs;

use App\ProtectedOperations\SellerSimulation\ProtectedSimulationRuntime;
use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\RuntimeSimulation;
use App\Seller\Evaluation\ScenarioTranscript;
use App\Seller\Evaluation\SimulationKnowledgeRelease;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Sandbox\SandboxRuntime;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
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
                $turns = [];
                $history = [];
                $state = [];
                $clients = array_slice(app(ScenarioTranscript::class)->clients($payload), 0, min(count($this->turns), (int) $persona->max_turns));
                foreach ($clients as $client) {
                    try {
                        $result = app(SandboxRuntime::class)->turn(Request::create('/'), $snapshot, $client, $state, $history, false, null);
                    } catch (\Throwable) {
                        $result = ['answer' => 'Нет подходящей безопасной тестовой фикстуры для ответа.', 'state' => $state,
                            'inspector' => ['guard' => ['verdict' => 'unavailable'], 'response_source' => 'guard_fallback',
                                'extraction_source' => 'unavailable', 'goal' => null, 'executed_tools' => []]];
                    }
                    $state = $result['state'];
                    $history[] = ['question' => $client, 'answer' => $result['answer']];
                    $turns[] = ['client' => $client, 'agent' => $result['answer'], 'meta' => [
                        'preview_only' => true, 'guard_verdict' => $result['inspector']['guard']['verdict'] ?? null,
                        'guard_fallback' => $result['inspector']['response_source'] === 'guard_fallback',
                        'extraction_source' => $result['inspector']['extraction_source'], 'response_source' => $result['inspector']['response_source'],
                        'slot_updates' => $result['inspector']['slot_updates'] ?? [],
                        'goal' => $result['inspector']['goal']['kind'] ?? null, 'tool_results' => $result['inspector']['executed_tools'],
                    ]];
                }

                return $simulation->run($tenant, $this->personaKey, $turns);
            });
        }

        return app(TenantContextRunner::class)->run($tenant, fn () => app(RuntimeSimulation::class)->run(
            $tenant, $this->personaKey, count($this->turns), app()->bound(SimulationRuntime::class)
            ? app(SimulationRuntime::class) : app(ProtectedSimulationRuntime::class), $this->clientModel
        ));
    }
}
