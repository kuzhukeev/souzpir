<?php

namespace App\Ai\Recovery;

use App\Contracts\Ai\AiResult;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class RecoverCall
{
    public function __construct(private ProviderEvidence $provider, private AuditWriter $audit) {}

    /** @return array{status:string, costMicros:int} */
    public function run(TenantContext $tenant, string $callId): array
    {
        $active = app(TenantContext::class);
        if ($tenant->organizationId === null || $tenant->brandId === null
            || $active->organizationId !== $tenant->organizationId || $active->brandId !== $tenant->brandId) {
            throw new LogicException('Call cannot be safely recovered.');
        }
        $query = DB::table('ai_calls')->where('id', $callId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $call = $query->first();
        if (! $call || ! in_array($call->status, ['reserved', 'uncertain'], true)
            || ! is_string($call->provider_generation_id) || $call->provider_generation_id === ''
            || ($call->status === 'reserved' && now()->lt(Carbon::parse($call->updated_at)->addMinutes(10)))) {
            throw new LogicException('Call cannot be safely recovered.');
        }
        $proof = $this->provider->forGeneration($call->provider_generation_id);

        return DB::transaction(function () use ($tenant, $call, $proof): array {
            $budget = DB::table('ai_budgets')->where('id', $call->budget_id)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->lockForUpdate()->first();
            $current = DB::table('ai_calls')->where('id', $call->id)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->lockForUpdate()->first();
            if (! $budget || ! $current || ! in_array($current->status, ['reserved', 'uncertain'], true)
                || $current->provider_generation_id !== $call->provider_generation_id
                || (int) $budget->reserved_micros < (int) $current->reserved_micros
                || (int) $budget->unresolved_count < 1) {
                throw new LogicException('Call cannot be safely recovered.');
            }
            $cost = $proof['costMicros'];
            $status = 'failed';
            $result = null;
            if (is_string($current->result_encrypted)) {
                try {
                    $result = AiResult::fromArray(json_decode(Crypt::decryptString($current->result_encrypted), true, 512, JSON_THROW_ON_ERROR));
                    if ($result->aiCallId !== $current->id || $result->costMicros !== $cost) {
                        $result = null;
                    }
                } catch (Throwable) {
                    $result = null;
                }
            }
            if ($result !== null) {
                $status = 'completed';
            }
            $breached = $cost > (int) $current->reserved_micros;
            DB::table('ai_budgets')->where('id', $budget->id)->update([
                'reserved_micros' => (int) $budget->reserved_micros - (int) $current->reserved_micros,
                'spent_micros' => (int) $budget->spent_micros + $cost,
                'unresolved_count' => (int) $budget->unresolved_count - 1,
                'reservation_breached' => (bool) $budget->reservation_breached || $breached,
                'updated_at' => now(),
            ]);
            DB::table('ai_calls')->where('id', $current->id)->update([
                'status' => $status, 'cost_micros' => $cost, 'reserve_exceeded' => $breached,
                'model' => $result?->model ?? $current->model,
                'prompt_tokens' => $result?->usage['promptTokens'] ?? null,
                'completion_tokens' => $result?->usage['completionTokens'] ?? null,
                'latency_ms' => $result?->latencyMs,
                'error_code' => $status === 'completed' ? null : ($cost === 0 ? 'reconciled_unbilled' : 'reconciled_billed_without_result'),
                'reconciliation_evidence_hash' => $proof['hash'], 'updated_at' => now(),
            ]);
            if ($cost > 0 || $result !== null) {
                $key = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'object_type' => $current->object_type, 'object_id' => $current->object_id];
                DB::table('object_costs')->insertOrIgnore([...$key, 'id' => (string) Str::ulid(),
                    'cost_micros' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0,
                    'created_at' => now(), 'updated_at' => now()]);
                DB::table('object_costs')->where($key)->incrementEach([
                    'cost_micros' => $cost,
                    'prompt_tokens' => $result?->usage['promptTokens'] ?? 0,
                    'completion_tokens' => $result?->usage['completionTokens'] ?? 0,
                ], ['updated_at' => now()]);
            }
            $this->audit->record($tenant, new AuditEntry('ai.call.reconciled', 'ai_call', $current->id,
                after: ['status' => $status, 'cost_micros' => $cost]));

            return ['status' => $status, 'costMicros' => $cost];
        });
    }
}
