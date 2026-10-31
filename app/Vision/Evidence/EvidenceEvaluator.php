<?php

namespace App\Vision\Evidence;

use App\Ai\CanonicalJson;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Only persisted tenant-bound answers and approved frozen knowledge may enter this boundary. */
final class EvidenceEvaluator
{
    public function __construct(
        private AiGateway $gateway,
        private EvidenceValidator $validator,
        private ConfirmationValidator $confirmation,
        private SourceExtractor $sources,
    ) {}

    /** @return array{status:string, fact:?array, mentions:array, sources:array, signature:?string} */
    public function evaluate(TenantContext $tenant, string $answerId): array
    {
        $unknown = ['status' => 'unknown', 'fact' => null, 'mentions' => [], 'sources' => [], 'signature' => null];
        $current = app(TenantContext::class);
        if (! $tenant->organizationId || ! $tenant->brandId || $tenant->organizationId !== $current->organizationId
            || $tenant->brandId !== $current->brandId || ! Str::isUlid($answerId)
            || ! app()->bound(KnowledgeGate::class)
            || config('runtime.ai_driver', 'fake') !== 'fake'
            || ! Schema::hasTable('measurements') || ! Schema::hasTable('measurement_answers')) {
            return $unknown;
        }
        try {
            $knowledge = app(KnowledgeGate::class);
            if (! $knowledge instanceof KnowledgeGate) {
                return $unknown;
            }
            $record = $this->record($tenant, $answerId);
            if (! $record || $record->status !== 'ok' || ! $record->protocol_valid || ! is_string($record->answer_text) || $record->answer_text === ''
                || ! is_string($record->profile_checksum) || ! is_string($record->profile_snapshot) || ! is_string($record->configuration)) {
                return $unknown;
            }
            $profile = json_decode($record->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
            $config = json_decode($record->configuration, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($profile) || ! is_array($config) || ! $this->validProfile($profile)
                || ! hash_equals($record->profile_checksum, CanonicalJson::hash($profile))
                || ! is_array($config['parser'] ?? null) || ! $this->validParser($config['parser'])) {
                return $unknown;
            }
            $knowledge->assertAllowed($tenant, $record->measurement_id, $profile);
            $artifacts = $this->artifacts();
            $signature = CanonicalJson::hash(['answer_id' => $answerId, 'answer_hash' => hash('sha256', $record->answer_text),
                'profile_checksum' => $record->profile_checksum, 'parser' => $config['parser'], 'artifacts' => $artifacts]);
            $prompt = file_get_contents(resource_path('prompts/Vision/Evidence/measure_parse.md'));
            $body = json_encode(['frozen_profile' => $profile, 'answer_data' => $record->answer_text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $format = ['type' => 'json_schema', 'json_schema' => ['name' => 'visibility_answer', 'strict' => true, 'schema' => $this->validator->schema()]];
            $context = new AiContext($tenant->organizationId, $tenant->brandId, 'vision.answer.parse', 'vision', 'measurement', $record->measurement_id, 'evidence:'.$answerId.':'.$signature, ['knowledge_public']);
            $result = $this->gateway->complete(new AiRequest([['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => $body]],
                $config['parser']['model'], (float) $config['parser']['temperature'], $format, maxTokens: $config['parser']['max_tokens'],
                promptVersion: 'vision-evidence-v1', promptChecksum: $artifacts['prompt']), $context);
            $candidate = $result->ok() && $result->model === $config['parser']['model'] ? $result->json() : null;
            if (! is_array($candidate)) {
                return $unknown;
            }
            $candidate = $this->validator->deduplicateCompanies($candidate);
            if ($this->validator->validationReason($candidate, $record->answer_text, $profile) !== null) {
                return $unknown;
            }
            if (! $candidate['brand_mentioned'] && $this->validator->matchedAlias($record->answer_text, $profile) !== null) {
                // A generic or ambiguous alias cannot support a confident absence.
                return $unknown;
            }
            if ($candidate['brand_mentioned']) {
                $confirmed = $this->confirmation->validate($candidate['mention_quote'], $profile, null);
                if ($confirmed['status'] !== 'confirmed') {
                    $knowledge->assertAllowed($tenant, $record->measurement_id, $profile);
                    $promptFile = file_get_contents(resource_path('prompts/Vision/Evidence/mention-confirmer.ru.v1.md'));
                    $system = explode('## Пользовательское сообщение', explode('## Системное сообщение', $promptFile, 2)[1] ?? '', 2)[0];
                    if (trim($system) === '') {
                        return $unknown;
                    }
                    $schema = ['type' => 'object', 'additionalProperties' => false,
                        'required' => ['decision', 'evidence', 'matched_facts'],
                        'properties' => [
                            'decision' => ['type' => 'string', 'enum' => ['confirmed', 'possible', 'not_mentioned']],
                            'evidence' => ['type' => 'string', 'maxLength' => 200],
                            'matched_facts' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'maxLength' => 120]],
                        ]];
                    $confirmContext = new AiContext($tenant->organizationId, $tenant->brandId, 'vision.mention.confirm', 'vision', 'measurement',
                        $record->measurement_id, 'confirm:'.$answerId.':'.$signature, ['knowledge_public']);
                    $confirmInput = json_encode(['company' => $profile['name'], 'profile' => $profile, 'answer_text' => $record->answer_text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $check = $this->gateway->complete(new AiRequest([
                        ['role' => 'system', 'content' => trim($system)],
                        ['role' => 'user', 'content' => $confirmInput],
                    ], $config['parser']['model'], 0.0, ['type' => 'json_schema', 'json_schema' => ['name' => 'mention_confirmation', 'strict' => true, 'schema' => $schema]],
                        maxTokens: 500, promptVersion: 'vision-confirmation-v1', promptChecksum: $artifacts['confirmation_prompt']), $confirmContext);
                    $confirmed = $check->ok() && $check->model === $config['parser']['model']
                        ? $this->confirmation->validate($record->answer_text, $profile, $check->json())
                        : ['status' => 'unknown', 'evidence' => null];
                    if ($confirmed['status'] !== 'confirmed') {
                        return $unknown;
                    }
                }
            }

            $fresh = $this->record($tenant, $answerId);
            if (! $fresh || $fresh->status !== 'ok' || ! $fresh->protocol_valid
                || $fresh->answer_text !== $record->answer_text || $fresh->profile_checksum !== $record->profile_checksum
                || $fresh->profile_snapshot !== $record->profile_snapshot
                || $fresh->configuration !== $record->configuration || $fresh->measurement_id !== $record->measurement_id) {
                return $unknown;
            }
            $knowledge->assertAllowed($tenant, $record->measurement_id, $profile);

            return ['status' => 'confirmed', 'fact' => $candidate, 'mentions' => $candidate['companies'], 'sources' => $this->sources->extract($record->answer_text, $profile), 'signature' => $signature];
        } catch (Throwable) {
            // No source text, profile, provider exception or credentials leave this boundary.
            return $unknown;
        }
    }

    private function record(TenantContext $tenant, string $answerId): ?object
    {
        return DB::table('measurement_answers as answer')
            ->join('measurements as measurement', function ($join): void {
                $join->on('measurement.id', '=', 'answer.measurement_id')
                    ->on('measurement.organization_id', '=', 'answer.organization_id')
                    ->on('measurement.brand_id', '=', 'answer.brand_id');
            })->where('answer.id', $answerId)->where('answer.organization_id', $tenant->organizationId)
            ->where('answer.brand_id', $tenant->brandId)->select('answer.status', 'answer.protocol_valid', 'answer.answer_text',
                'answer.measurement_id', 'measurement.profile_snapshot', 'measurement.profile_checksum', 'measurement.configuration')->first();
    }

    private function validProfile(#[\SensitiveParameter] array $profile): bool
    {
        return is_string($profile['name'] ?? null) && $profile['name'] !== '' && mb_strlen($profile['name']) <= 200
            && is_array($profile['aliases'] ?? null) && array_is_list($profile['aliases'])
            && is_array($profile['facts'] ?? null) && array_is_list($profile['facts'])
            && strlen(json_encode($profile, JSON_THROW_ON_ERROR)) <= 100000;
    }

    private function validParser(array $parser): bool
    {
        $artifacts = $this->artifacts();
        if (! is_string($parser['model'] ?? null) || trim($parser['model']) === ''
            || ! is_numeric($parser['temperature'] ?? null) || (float) $parser['temperature'] < 0 || (float) $parser['temperature'] > 2
            || ! is_int($parser['max_tokens'] ?? null) || $parser['max_tokens'] < 1 || $parser['max_tokens'] > 4096) {
            return false;
        }
        foreach ($artifacts as $key => $hash) {
            if (! is_string($parser[$key] ?? null) || ! hash_equals($hash, $parser[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,string> */
    public function artifacts(): array
    {
        return [
            'prompt' => hash_file('sha256', resource_path('prompts/Vision/Evidence/measure_parse.md')),
            'schema' => hash_file('sha256', resource_path('prompts/Vision/Evidence/measure_parse.schema.json')),
            'confirmation_prompt' => hash_file('sha256', resource_path('prompts/Vision/Evidence/mention-confirmer.ru.v1.md')),
            'source_types' => hash_file('sha256', resource_path('prompts/Vision/Evidence/source-types.ru.json')),
            'normalizer_version' => EntityNormalizer::VERSION,
            'confirmation_version' => MentionConfirmer::VERSION,
        ];
    }
}
