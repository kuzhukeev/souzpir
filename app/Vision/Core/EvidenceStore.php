<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use App\Contracts\Ai\AiResult;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\Evidence\EvidenceEvaluator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Evidence is accepted only from the real evaluator for an unchanged gateway-backed answer. */
final class EvidenceStore
{
    public function __construct(private Tenant $tenant, private EvidenceEvaluator $evaluator) {}

    public function evaluate(string $answerId): string
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            return 'unknown';
        }
        try {
            $tenant = $this->tenant->current();
            $context = app(TenantContext::class);
            $before = $this->records($tenant, $answerId);
            if (! $before || ! $this->validAnswer(...$before)) {
                return 'unknown';
            }
            [$answer, $measurement, $call] = $before;
            $fingerprint = $this->fingerprint($answer, $measurement, $call);
            $result = $this->evaluator->evaluate($context, $answerId);
            if (($result['status'] ?? null) !== 'confirmed' || ! is_array($result['fact'] ?? null)
                || ! is_array($result['mentions'] ?? null) || ! is_array($result['sources'] ?? null)
                || ! is_bool($result['mentions_complete'] ?? null) || ! is_string($result['signature'] ?? null)
                || ! hash_equals($this->expectedSignature($answer, $measurement), $result['signature'])) {
                return 'unknown';
            }

            return DB::transaction(function () use ($tenant, $context, $answerId, $measurement, $fingerprint, $result): string {
                MeasurementLock::acquire($measurement->id);
                $profile = json_decode($measurement->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($profile)) {
                    return 'unknown';
                }
                // Gate locks organization → brand → membership → release → grant → measurement.
                app(KnowledgeGate::class)->assertAllowed($context, $measurement->id, $profile);
                $current = $this->records($tenant, $answerId, true);
                if (! $current || ! hash_equals($fingerprint, $this->fingerprint(...$current)) || ! $this->validAnswer(...$current)
                    || ! hash_equals($this->expectedSignature($current[0], $current[1]), $result['signature'])) {
                    return 'unknown';
                }
                $stored = ['fact' => $result['fact'], 'mentions' => $result['mentions'], 'sources' => $result['sources'],
                    'mentions_complete' => $result['mentions_complete'], 'signature' => $result['signature']];
                DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->update([
                    'fact' => json_encode($stored['fact'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'mentions' => json_encode($stored['mentions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'sources' => json_encode($stored['sources'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'mentions_complete' => $stored['mentions_complete'], 'evidence_signature' => $stored['signature'],
                    'evidence_mac' => $this->mac($stored), 'updated_at' => now(),
                ]);

                return 'confirmed';
            });
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /** Caller must verify authorization within its enclosing transaction. */
    public function verifiedEvidence(object $answer, object $measurement, object $call): ?array
    {
        try {
            if (! $this->validAnswer($answer, $measurement, $call) || ! is_string($answer->evidence_signature)
                || ! is_string($answer->evidence_mac) || $answer->mentions_complete === null
                || ! is_string($answer->fact) || ! is_string($answer->mentions) || ! is_string($answer->sources)) {
                return null;
            }
            $stored = ['fact' => json_decode($answer->fact, true, 64, JSON_THROW_ON_ERROR),
                'mentions' => json_decode($answer->mentions, true, 64, JSON_THROW_ON_ERROR),
                'sources' => json_decode($answer->sources, true, 64, JSON_THROW_ON_ERROR),
                'mentions_complete' => (bool) $answer->mentions_complete, 'signature' => $answer->evidence_signature];
            if (! is_array($stored['fact']) || ! is_array($stored['mentions']) || ! is_array($stored['sources'])
                || ! hash_equals($this->expectedSignature($answer, $measurement), $answer->evidence_signature)
                || ! hash_equals($this->mac($stored), $answer->evidence_mac)) {
                return null;
            }

            return $stored;
        } catch (Throwable) {
            return null;
        }
    }

    private function records(array $tenant, string $answerId, bool $lock = false): ?array
    {
        $reference = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->first([
            'measurement_id', 'ai_call_id',
            DB::raw('coalesce(octet_length(answer_text), 0) + coalesce(octet_length(fact::text), 0) + coalesce(octet_length(mentions::text), 0) + coalesce(octet_length(sources::text), 0) as data_bytes'),
        ]);
        if (! $reference || ! $reference->ai_call_id || ! is_numeric($reference->data_bytes)
            || (int) $reference->data_bytes > WorkloadLimits::dataBytes()) {
            return null;
        }
        MeasurementPlan::fetch($tenant, $reference->measurement_id);
        $mq = DB::table('measurements')->where($tenant)->where('id', $reference->measurement_id);
        $measurement = ($lock ? $mq->lockForUpdate() : $mq)->first();
        $aq = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->where('measurement_id', $reference->measurement_id)
            ->where('ai_call_id', $reference->ai_call_id)
            ->whereRaw('coalesce(octet_length(answer_text), 0) + coalesce(octet_length(fact::text), 0) + coalesce(octet_length(mentions::text), 0) + coalesce(octet_length(sources::text), 0) <= ?', [WorkloadLimits::dataBytes()]);
        $answer = ($lock ? $aq->lockForUpdate() : $aq)->first();
        if (! $measurement || ! $answer || ! $answer->ai_call_id) {
            return null;
        }
        $callSize = DB::table('ai_calls')->where($tenant)->where('id', $answer->ai_call_id)
            ->first([DB::raw('octet_length(result_encrypted) as data_bytes')]);
        if (! $callSize || ! is_numeric($callSize->data_bytes) || (int) $callSize->data_bytes > WorkloadLimits::dataBytes()) {
            return null;
        }
        $cq = DB::table('ai_calls')->where($tenant)->where('id', $answer->ai_call_id)
            ->whereRaw('octet_length(result_encrypted) <= ?', [WorkloadLimits::dataBytes()]);
        $call = ($lock ? $cq->lockForUpdate() : $cq)->first(['id', 'status', 'purpose', 'family', 'object_type', 'object_id', 'requested_model', 'model', 'idempotency_key_hash', 'result_encrypted']);

        return $call ? [$answer, $measurement, $call] : null;
    }

    private function validAnswer(object $answer, object $measurement, object $call): bool
    {
        if ($answer->status !== 'ok' || ! $answer->protocol_valid || ! is_string($answer->answer_text) || $answer->answer_text === ''
            || ! is_string($measurement->profile_snapshot) || ! is_string($measurement->profile_checksum) || ! is_string($measurement->configuration)
            || $answer->ai_call_id !== $call->id || $call->status !== 'completed' || $call->purpose !== 'vis.measure'
            || $call->family !== 'vision' || $call->object_type !== 'measurement' || $call->object_id !== $measurement->id
            || $call->requested_model !== $answer->requested_model || $call->model !== $answer->model
            || $call->idempotency_key_hash !== hash_hmac('sha256', 'vision-measure:'.$answer->id, (string) config('app.key'))
            || ! is_string($call->result_encrypted)
            || strlen($call->result_encrypted) > WorkloadLimits::dataBytes()
            || strlen($answer->answer_text) + strlen((string) ($answer->fact ?? '')) + strlen((string) ($answer->mentions ?? '')) + strlen((string) ($answer->sources ?? '')) > WorkloadLimits::dataBytes()) {
            return false;
        }
        $current = MeasurementPlan::fetch($this->tenant->current(), $measurement->id);
        if (! is_string($current->configuration) || ! hash_equals($current->configuration, $measurement->configuration)) {
            return false;
        }
        $profile = json_decode($measurement->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($profile) || ! hash_equals($measurement->profile_checksum, CanonicalJson::hash($profile))) {
            return false;
        }
        $result = AiResult::fromArray(json_decode(Crypt::decryptString($call->result_encrypted), true, 64, JSON_THROW_ON_ERROR));

        return $result->ok() && $result->aiCallId === $call->id && $result->model === $answer->model
            && $answer->requested_model === $answer->model && $result->content === $answer->answer_text;
    }

    private function fingerprint(object $answer, object $measurement, object $call): string
    {
        return CanonicalJson::hash(['id' => $answer->id, 'measurement' => $measurement->id, 'status' => $answer->status,
            'protocol' => $answer->protocol_valid, 'answer' => $answer->answer_text, 'call' => $call->id,
            'call_status' => $call->status, 'call_result' => $call->result_encrypted, 'call_key' => $call->idempotency_key_hash,
            'requested_model' => $answer->requested_model, 'model' => $answer->model, 'config' => $measurement->configuration,
            'profile' => $measurement->profile_snapshot, 'profile_checksum' => $measurement->profile_checksum]);
    }

    private function expectedSignature(object $answer, object $measurement): string
    {
        $config = json_decode($measurement->configuration, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($config['parser'] ?? null)) {
            throw new \LogicException;
        }

        return CanonicalJson::hash(['answer_id' => $answer->id, 'answer_hash' => hash('sha256', $answer->answer_text),
            'profile_checksum' => $measurement->profile_checksum, 'parser' => $config['parser'], 'artifacts' => $this->evaluator->artifacts()]);
    }

    private function mac(#[\SensitiveParameter] array $stored): string
    {
        return hash_hmac('sha256', 'vision-evidence:'.CanonicalJson::hash($stored), (string) config('app.key'));
    }
}
