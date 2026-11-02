<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\Rejected;
use App\Ai\ProviderCatalog;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\Evidence\EvidenceEvaluator;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class MeasurementRunner
{
    public function __construct(private Tenant $tenant, private AiGateway $gateway) {}

    /** @param array<string,string> $providers Provider key => active model. */
    public function start(string $questionSetId, array $providers, int $runs): string
    {
        $tenant = $this->tenant->current();
        if ($runs < 1 || $providers === [] || array_is_list($providers)) {
            throw new InvalidArgumentException('A provider panel and positive repeat count are required.');
        }
        $maxSlots = WorkloadLimits::limit('max_slots');
        if ($runs > $maxSlots || count($providers) > $maxSlots) {
            throw new InvalidArgumentException('Vision measurement workload exceeds the technical limit.');
        }
        $temperature = config('features.E8-01.defaults.temperature');
        $maxTokens = config('features.E8-01.defaults.max_output_tokens');
        if ((! is_float($temperature) && ! is_int($temperature)) || ! is_finite((float) $temperature)
            || $temperature < 0 || $temperature > 2 || ! is_int($maxTokens) || $maxTokens < 1) {
            throw new LogicException('The visibility measurement protocol is not configured.');
        }
        $temperature = (float) $temperature;
        $models = app(ProviderCatalog::class)->models();
        foreach ($providers as $provider => $model) {
            if (! is_string($provider) || $provider === '' || strlen($provider) > 100 || ! is_string($model)
                || ($models[$model]['status'] ?? null) !== 'active' || ($models[$model]['provider'] ?? null) !== $provider) {
                throw new InvalidArgumentException('Only configured active provider models are allowed.');
            }
        }
        ksort($providers);
        $parser = config('features.E8-01.defaults.parser');
        if (! is_array($parser) || array_diff(array_keys($parser), ['model', 'temperature', 'max_tokens'])
            || ! is_string($parser['model'] ?? null) || ($models[$parser['model']]['status'] ?? null) !== 'active'
            || (! is_float($parser['temperature'] ?? null) && ! is_int($parser['temperature'] ?? null))
            || ! is_finite((float) $parser['temperature']) || $parser['temperature'] < 0 || $parser['temperature'] > 2
            || ! is_int($parser['max_tokens'] ?? null) || $parser['max_tokens'] < 1 || $parser['max_tokens'] > 4096) {
            throw new LogicException('The trusted evidence parser is not configured.');
        }
        $parser = [...$parser, ...app(EvidenceEvaluator::class)->artifacts()];
        $context = app(TenantContext::class);
        // Question sets are immutable; check the full grid before the write transaction.
        $planned = DB::table('question_sets')->where($tenant)->where('id', $questionSetId)
            ->first(['size', DB::raw('octet_length(questions::text) as question_bytes')]);
        if (! $planned || ! is_numeric($planned->question_bytes) || (int) $planned->question_bytes > WorkloadLimits::limit('max_questions_bytes')) {
            throw new LogicException('Vision measurement plan is unavailable.');
        }
        WorkloadLimits::grid((int) $planned->size, count($providers), $runs);

        try {
            return DB::transaction(function () use ($questionSetId, $providers, $runs, $tenant, $temperature, $maxTokens, $parser, $context): string {
                $set = DB::table('question_sets')->where($tenant)->where('id', $questionSetId)->first();
                if (! $set) {
                    throw new LogicException('Question set not found.');
                }
                $questions = json_decode($set->questions, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($questions) || count($questions) !== $set->size || ! hash_equals($set->checksum, CanonicalJson::hash($questions))) {
                    throw new LogicException('Question set integrity check failed.');
                }
                $profile = app(PublishedKnowledgeBridge::class)->buildProfile($context);
                $id = (string) Str::ulid();
                DB::table('measurements')->insert(['id' => $id, ...$tenant, 'question_set_id' => $questionSetId,
                    'status' => 'pending', 'configuration' => json_encode(['questions' => $questions, 'question_set_checksum' => $set->checksum,
                        'providers' => $providers, 'runs' => $runs, 'temperature' => $temperature, 'max_output_tokens' => $maxTokens, 'parser' => $parser], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                    'profile_snapshot' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile),
                    'created_at' => now(), 'updated_at' => now()]);
                foreach ($providers as $provider => $model) {
                    foreach ($questions as $index => $question) {
                        for ($run = 1; $run <= $runs; $run++) {
                            DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), ...$tenant,
                                'measurement_id' => $id, 'question_n' => $index + 1, 'provider' => $provider,
                                'requested_model' => $model, 'run_no' => $run, 'status' => 'pending',
                                'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }

                return $id;
            });
        } catch (Throwable) {
            throw new LogicException('Vision measurement could not be safely started.');
        }
    }

    /**
     * Only trusted workers call this. No network/live execution is enabled.
     * Crashed or uncertain attempts are never automatically retried.
     */
    public function measure(string $answerId): string
    {
        $tenant = $this->tenant->current();
        if (config('runtime.ai_driver', 'fake') !== 'fake' && ! config('eyon.live_measurements_enabled', false)) {
            throw new LogicException('Live Vision measurement is disabled.');
        }
        try {
            $claimed = DB::transaction(function () use ($answerId, $tenant): ?array {
                $reference = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->first(['measurement_id']);
                if (! $reference) {
                    throw new LogicException('Measurement answer not found.');
                }
                MeasurementLock::acquire($reference->measurement_id);
                // The gate acquires organization, brand and grant before the measurement.
                $candidate = MeasurementPlan::fetch($tenant, $reference->measurement_id);
                $this->assertKnowledge($candidate);
                $measurement = DB::table('measurements')->where($tenant)->where('id', $reference->measurement_id)->lockForUpdate()->first();
                if (! $measurement) {
                    throw new LogicException('Measurement not found.');
                }
                $answer = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)
                    ->where('measurement_id', $measurement->id)
                    ->whereRaw('coalesce(octet_length(answer_text), 0) + coalesce(octet_length(fact::text), 0) + coalesce(octet_length(mentions::text), 0) + coalesce(octet_length(sources::text), 0) <= ?', [WorkloadLimits::dataBytes()])
                    ->lockForUpdate()->first();
                if (! $answer) {
                    throw new LogicException('Measurement answer not found.');
                }
                if ($answer->status !== 'pending') {
                    return null;
                }
                $configuration = json_decode($measurement->configuration, true, flags: JSON_THROW_ON_ERROR);
                $questions = $configuration['questions'] ?? null;
                if (! is_array($questions) || ! hash_equals((string) ($configuration['question_set_checksum'] ?? ''), CanonicalJson::hash($questions))) {
                    throw new LogicException('Frozen questions failed integrity validation.');
                }
                $question = $questions[$answer->question_n - 1] ?? null;
                if (! is_array($question) || ! is_string($question['text'] ?? null)
                    || ($configuration['providers'][$answer->provider] ?? null) !== $answer->requested_model
                    || $answer->run_no < 1 || $answer->run_no > ($configuration['runs'] ?? 0)) {
                    throw new LogicException('Measurement slot is inconsistent with its plan.');
                }
                DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->update(['status' => 'in_progress', 'updated_at' => now()]);
                DB::table('measurements')->where($tenant)->where('id', $measurement->id)->update(['status' => 'running', 'started_at' => $measurement->started_at ?? now(), 'updated_at' => now()]);

                return [$answer, $question, $configuration];
            });
        } catch (Throwable) {
            throw new LogicException('Vision measurement could not be safely completed.');
        }
        if ($claimed === null) {
            return (string) DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->value('status');
        }
        [$answer, $question, $configuration] = $claimed;
        try {
            DB::transaction(function () use ($tenant, $answer): void {
                $measurement = MeasurementPlan::fetch($tenant, $answer->measurement_id);
                $this->assertKnowledge($measurement);
            });
        } catch (Throwable) {
            return $this->finish($tenant, $answerId, $answer->measurement_id, ['status' => 'failed', 'error_code' => 'knowledge_unavailable']);
        }
        try {
            $result = $this->gateway->complete(
                new AiRequest([['role' => 'user', 'content' => $question['text']]], $answer->requested_model, (float) $configuration['temperature'], maxTokens: $configuration['max_output_tokens']),
                new AiContext($tenant['organization_id'], $tenant['brand_id'], 'vis.measure', 'vision',
                    'measurement', $answer->measurement_id, 'vision-measure:'.$answerId, ['vision_question']),
            );
        } catch (Rejected) {
            $this->finish($tenant, $answerId, $answer->measurement_id, ['status' => 'failed', 'error_code' => 'provider_rejected']);

            return 'failed';
        } catch (Throwable) {
            $this->finish($tenant, $answerId, $answer->measurement_id, ['status' => 'uncertain', 'error_code' => 'provider_uncertain']);
            throw new LogicException('Vision measurement could not be safely completed.');
        }
        $status = $result->error === null ? 'ok' : match ($result->error['code'] ?? '') {
            'in_progress' => 'in_progress',
            'empty_response' => 'failed',
            default => 'uncertain',
        };

        return $this->finish($tenant, $answerId, $answer->measurement_id, [
            'status' => $status, 'ai_call_id' => $result->aiCallId,
            'answer_text' => $status === 'ok' ? $result->content : null, 'model' => $result->model,
            'protocol_valid' => $status === 'ok' ? $result->model === $answer->requested_model : null,
            'error_code' => $result->error['code'] ?? null,
        ]);
    }

    private function finish(array $tenant, string $answerId, string $measurementId, #[\SensitiveParameter] array $changes): string
    {
        try {
            return DB::transaction(function () use ($tenant, $answerId, $measurementId, $changes): string {
                MeasurementLock::acquire($measurementId);
                try {
                    $candidate = MeasurementPlan::fetch($tenant, $measurementId);
                    $this->assertKnowledge($candidate);
                } catch (Throwable) {
                    $changes = ['status' => 'failed', 'error_code' => 'knowledge_unavailable', 'answer_text' => null, 'protocol_valid' => null];
                }
                $measurement = DB::table('measurements')->where($tenant)->where('id', $measurementId)->lockForUpdate()->first(['id']);
                if (! $measurement) {
                    throw new LogicException('Measurement not found.');
                }
                $updated = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)
                    ->where('status', 'in_progress')->update([...$changes, 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new LogicException('Measurement slot changed while processing.');
                }
                $pending = DB::table('measurement_answers')->where($tenant)->where('measurement_id', $measurementId)
                    ->whereIn('status', ['pending', 'in_progress'])->exists();
                if (! $pending) {
                    $partial = DB::table('measurement_answers')->where($tenant)->where('measurement_id', $measurementId)
                        ->whereIn('status', ['failed', 'uncertain'])->exists();
                    $successful = DB::table('measurement_answers')->where($tenant)->where('measurement_id', $measurementId)->where('status', 'ok')->exists();
                    DB::table('measurements')->where($tenant)->where('id', $measurementId)->update([
                        'status' => $partial ? ($successful ? 'partial' : 'failed') : 'completed', 'finished_at' => now(), 'updated_at' => now(),
                    ]);
                    if (! $partial) {
                        app(NotificationDispatcher::class)->enqueue(new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'vision.measurement.completed',
                            'occurred_at' => now()->toIso8601String(), 'organization_id' => $tenant['organization_id'], 'brand_id' => $tenant['brand_id'], 'actor_id' => null,
                            'aggregate' => ['type' => 'measurement', 'id' => $measurementId, 'version' => 1], 'data' => ['measurement_id' => $measurementId]]));
                    }
                }

                return $changes['status'];
            });
        } catch (Throwable) {
            throw new LogicException('Vision measurement could not be safely completed.');
        }
    }

    private function assertKnowledge(#[\SensitiveParameter] ?object $measurement): void
    {
        if (! $measurement || ! is_string($measurement->profile_snapshot) || ! is_string($measurement->profile_checksum)) {
            throw new LogicException('An approved frozen profile is required.');
        }
        $profile = json_decode($measurement->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($profile) || ! hash_equals($measurement->profile_checksum, CanonicalJson::hash($profile))) {
            throw new LogicException('An approved frozen profile is required.');
        }
        app(KnowledgeGate::class)->assertAllowed(app(TenantContext::class), $measurement->id, $profile);
    }
}
