<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\Rejected;
use App\Ai\ProviderCatalog;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
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

        return DB::transaction(function () use ($questionSetId, $providers, $runs, $tenant, $temperature, $maxTokens): string {
            $set = DB::table('question_sets')->where($tenant)->where('id', $questionSetId)->first();
            if (! $set) {
                throw new LogicException('Question set not found.');
            }
            $questions = json_decode($set->questions, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($questions) || count($questions) !== $set->size || ! hash_equals($set->checksum, CanonicalJson::hash($questions))) {
                throw new LogicException('Question set integrity check failed.');
            }
            $id = (string) Str::ulid();
            DB::table('measurements')->insert(['id' => $id, ...$tenant, 'question_set_id' => $questionSetId,
                'status' => 'pending', 'configuration' => json_encode(['questions' => $questions, 'question_set_checksum' => $set->checksum,
                    'providers' => $providers, 'runs' => $runs, 'temperature' => $temperature, 'max_output_tokens' => $maxTokens, 'parser' => null], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
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
    }

    /**
     * Only trusted workers call this. No network/live execution is enabled.
     * Crashed or uncertain attempts are never automatically retried.
     */
    public function measure(string $answerId): string
    {
        $tenant = $this->tenant->current();
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Vision measurement is restricted to fake AI until an authorized live stage.');
        }
        $claimed = DB::transaction(function () use ($answerId, $tenant): ?array {
            $reference = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->first();
            if (! $reference) {
                throw new LogicException('Measurement answer not found.');
            }
            // Always acquire parent before slot, matching finish() to avoid deadlocks.
            $measurement = DB::table('measurements')->where($tenant)->where('id', $reference->measurement_id)->lockForUpdate()->first();
            if (! $measurement) {
                throw new LogicException('Measurement not found.');
            }
            $answer = DB::table('measurement_answers')->where($tenant)->where('id', $answerId)
                ->where('measurement_id', $measurement->id)->lockForUpdate()->first();
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
        if ($claimed === null) {
            return (string) DB::table('measurement_answers')->where($tenant)->where('id', $answerId)->value('status');
        }
        [$answer, $question, $configuration] = $claimed;
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
        $this->finish($tenant, $answerId, $answer->measurement_id, [
            'status' => $status, 'ai_call_id' => $result->aiCallId,
            'answer_text' => $status === 'ok' ? $result->content : null, 'model' => $result->model,
            'protocol_valid' => $status === 'ok' ? $result->model === $answer->requested_model : null,
            'error_code' => $result->error['code'] ?? null,
        ]);

        return $status;
    }

    private function finish(array $tenant, string $answerId, string $measurementId, array $changes): void
    {
        DB::transaction(function () use ($tenant, $answerId, $measurementId, $changes): void {
            $measurement = DB::table('measurements')->where($tenant)->where('id', $measurementId)->lockForUpdate()->first();
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
                DB::table('measurements')->where($tenant)->where('id', $measurementId)->update([
                    'status' => $partial ? 'partial' : 'completed', 'finished_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
