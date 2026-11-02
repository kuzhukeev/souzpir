<?php

namespace App\Seller\Evaluation;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FakeSimulation
{
    public function run(TenantContext $context, string $personaKey, array $turns): string
    {
        $persona = DB::table('seller_personas')->where('organization_id', $context->requireOrganizationId())
            ->where('brand_id', $context->brandId)->where('key', $personaKey)->first() ?? abort(404);
        if ($turns === [] || count($turns) > $persona->max_turns) {
            throw new InvalidArgumentException('Invalid number of fake turns.');
        }
        foreach ($turns as $turn) {
            if (! is_array($turn) || ! is_string($turn['client'] ?? null)
                || ! is_string($turn['agent'] ?? null) || ! is_array($turn['meta'] ?? [])) {
                throw new InvalidArgumentException('Invalid fake turn.');
            }
        }
        $id = (string) Str::ulid();
        $fallbacks = count(array_filter($turns, fn ($turn) => ($turn['meta']['guard_fallback'] ?? false) === true));
        DB::transaction(function () use ($context, $persona, $turns, $id, $fallbacks): void {
            DB::table('seller_sim_runs')->insert(['id' => $id, 'organization_id' => $context->organizationId,
                'brand_id' => $context->brandId, 'persona_id' => $persona->id, 'status' => 'completed',
                'turn_count' => count($turns), 'turns' => json_encode($turns, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'metrics' => json_encode(['agent_turns' => count($turns), 'guard_fallbacks' => $fallbacks], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.simulation.completed', 'seller_sim_run', $id,
                after: ['persona_id' => $persona->id, 'turn_count' => count($turns)]));
        });

        return $id;
    }

    public function judge(TenantContext $context, string $runId, array $result, Supervisor $supervisor): string
    {
        $run = DB::table('seller_sim_runs')->where('organization_id', $context->requireOrganizationId())
            ->where('brand_id', $context->brandId)->where('id', $runId)->first() ?? abort(404);
        $normal = $supervisor->normalize($result, $run->turn_count);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($context, $runId, $normal, $id): void {
            DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $context->organizationId,
                'brand_id' => $context->brandId, 'sim_run_id' => $runId,
                'scores' => json_encode($normal['scores'], JSON_THROW_ON_ERROR),
                'issues' => json_encode($normal['issues'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'average' => $normal['average'], 'comment' => $normal['comment'],
                'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.evaluation.created', 'seller_evaluation', $id,
                after: ['sim_run_id' => $runId, 'average' => $normal['average']]));
        });

        return $id;
    }
}
