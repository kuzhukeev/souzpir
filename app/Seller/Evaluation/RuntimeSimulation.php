<?php

namespace App\Seller\Evaluation;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class RuntimeSimulation
{
    public function run(TenantContext $context, string $personaKey, int $maxTurns, SimulationRuntime $runtime, string $clientModel = 'fake'): string
    {
        $persona = DB::table('seller_personas')->where('organization_id', $context->requireOrganizationId())
            ->where('brand_id', $context->brandId)->where('key', $personaKey)->first() ?? abort(404);
        if ($maxTurns < 1 || $maxTurns > $persona->max_turns) {
            throw new InvalidArgumentException('Invalid number of turns.');
        }
        $strategy = ClientModel::strategy($clientModel);
        $payload = json_decode($persona->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Invalid persona.');
        }
        $id = (string) Str::ulid();
        $trace = [];
        $metrics = ['client_model' => $clientModel, 'knowledge_release_id' => app(SimulationKnowledgeRelease::class)->id($context)];
        DB::table('seller_sim_runs')->insert(['id' => $id, 'organization_id' => $context->organizationId,
            'brand_id' => $context->brandId, 'persona_id' => $persona->id, 'status' => 'processing',
            'turn_count' => 0, 'turns' => '[]', 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $status = 'completed';
        try {
            $opened = $runtime->open($context, $id.':open');
            $conversationId = $opened['conversation_id'] ?? null;
            if (! is_string($conversationId) || ! Str::isUlid($conversationId)) {
                throw new InvalidArgumentException('Runtime returned no conversation.');
            }
            $trace[] = ['client' => '', 'agent' => $this->agentText($opened)];
            for ($index = 1; $index < $maxTurns; $index++) {
                $client = $this->clientText($payload, $index, $strategy);
                $reply = $runtime->turn($context, $conversationId, $client, $id.':'.$index);
                $trace[] = ['client' => $client, 'agent' => $this->agentText($reply)];
            }
        } catch (Throwable $error) {
            $status = 'failed';
            $metrics['error'] = $error::class;
        }
        $metrics['agent_turns'] = count($trace);
        DB::transaction(function () use ($context, $id, $status, $trace, $metrics): void {
            DB::table('seller_sim_runs')->where('id', $id)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->update(['status' => $status, 'turn_count' => count($trace),
                    'turns' => json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.simulation.'.$status, 'seller_sim_run', $id,
                after: ['turn_count' => count($trace)]));
        });

        return $id;
    }

    private function agentText(array $reply): string
    {
        $text = $reply['agent'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Runtime returned no agent text.');
        }

        return $text;
    }

    private function clientText(array $persona, int $index, string $strategy): string
    {
        $facts = $strategy === 'facts' ? ($persona['facts'] ?? []) : [];
        if (! is_array($facts)) {
            $facts = [];
        }
        $values = array_values(array_filter($facts, fn (mixed $value): bool => is_scalar($value) && (string) $value !== ''));
        if ($values === []) {
            return (string) ($persona['behavior'] ?? 'Расскажите подробнее.');
        }

        return 'Уточню: '.(string) $values[($index - 1) % count($values)];
    }
}
