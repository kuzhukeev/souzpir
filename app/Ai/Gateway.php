<?php

namespace App\Ai;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\OpenRouterDriver;
use App\Ai\Drivers\Rejected;
use App\Ai\Drivers\Uncertain;
use App\Ai\Protected\ApiFollowupInput;
use App\Ai\Protected\ApiOpeningInput;
use App\Ai\Protected\RawToolArguments;
use App\Ai\Protected\RequestFingerprint;
use App\Ai\Protected\SellerExtractorStages;
use App\Ai\Protected\SellerGuardStages;
use App\Ai\Protected\SellerResponderStages;
use App\Ai\Protected\SellerSummaryStages;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Privacy\ContactBoundary;
use App\Privacy\Secrets\SecretBoundary;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Dialogs\ManagerSuggestionExtractorInput;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\GuardModelResult;
use App\Seller\Engine\ResponderResult;
use App\Seller\Engine\ToolArguments;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class Gateway implements AiGateway
{
    /** Fake-only model guard; stage references do not authorize an effect. */
    public function completeProtectedModelGuard(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                throw new LogicException;
            }
            $stages = app(SellerGuardStages::class);
            [$request, $context, $digest] = $stages->load($http, $claim, $stageId);
            $result = $this->completeInternal($request, $context,
                fn () => $stages->lockForGateway($http, $claim, $stageId, $digest),
                fn (object $call, string $hash) => $stages->bindCall($http, $claim, $stageId, $call, $hash));
            if (($result->error['code'] ?? null) === 'in_progress') {
                return 'in_progress';
            }

            return $result->costMicros === null ? 'uncertain' : 'completed';
        } catch (Throwable) {
            throw new LogicException('Protected model guard stage could not be completed.');
        }
    }

    /** Internal decision only, read under the caller's transaction. */
    public function readProtectedModelGuardLocked(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            [$request, $context, , $callId, $requestHash, $deterministic, $goal] = app(SellerGuardStages::class)->load($http, $claim, $stageId);
            if (! is_string($callId) || ! is_string($requestHash) || $request->tools !== [] || $request->toolChoice !== null) {
                throw new LogicException;
            }
            $body = app(ContactMasker::class)->request($request->toArray());
            $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
            $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->lockForUpdate()->first();
            if (! $call || $call->status !== 'completed' || ! hash_equals($expected, $requestHash)
                || ! hash_equals($expected, $call->request_hash)
                || ! hash_equals(hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key')), $call->idempotency_key_hash)) {
                throw new LogicException;
            }
            $result = $this->replay($call, $request->model);
            $this->assertResult($result->toArray());
            if ($result->costMicros === null || $call->cost_micros === null || (int) $call->cost_micros !== $result->costMicros
                || $call->model !== $result->model || $call->prompt_tokens === null || (int) $call->prompt_tokens !== $result->usage['promptTokens']
                || $call->completion_tokens === null || (int) $call->completion_tokens !== $result->usage['completionTokens']
                || $call->latency_ms === null || (int) $call->latency_ms !== $result->latencyMs) {
                throw new LogicException;
            }
            $model = app(GuardModelResult::class)->interpret($result);

            return app(GuardDecision::class)->finish($deterministic, $goal, true, $model);
        } catch (Throwable) {
            throw new LogicException('Protected model guard result unavailable.');
        }
    }

    /** Fake-only first-turn responder; its return value cannot authorize an effect. */
    public function completeProtectedResponder(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                throw new LogicException;
            }
            $stages = app(SellerResponderStages::class);
            [$request, $context, $digest] = $stages->load($http, $claim, $stageId);
            $result = $this->completeInternal($request, $context,
                fn () => $stages->lockForGateway($http, $claim, $stageId, $digest),
                fn (object $call, string $hash) => $stages->bindCall($http, $claim, $stageId, $call, $hash));
            if (($result->error['code'] ?? null) === 'in_progress') {
                return 'in_progress';
            }

            return $result->costMicros === null ? 'uncertain' : 'completed';
        } catch (Throwable) {
            throw new LogicException('Protected responder stage could not be completed.');
        }
    }

    /** Fake-only opt-in: capture raw arguments from the same driver invocation. */
    public function completeProtectedResponderWithRaw(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                throw new LogicException;
            }
            $stages = app(SellerResponderStages::class);
            [$request, $context, $digest] = $stages->load($http, $claim, $stageId);
            $result = $this->completeInternal($request, $context,
                fn () => $stages->lockForGateway($http, $claim, $stageId, $digest),
                fn (object $call, string $hash) => $stages->bindCall($http, $claim, $stageId, $call, $hash),
                function (object $call, AiResult $result, array $raw) use ($http, $claim, $stageId, $stages): void {
                    [, $context, , $boundCall, $requestHash] = $stages->load($http, $claim, $stageId);
                    if ($boundCall !== $call->id || ! is_string($requestHash) || ! hash_equals($requestHash, $call->request_hash)) {
                        throw new LogicException;
                    }
                    $evidence = ['stage_id' => $stageId, 'organization_id' => $context->organizationId,
                        'brand_id' => $context->brandId, 'conversation_id' => $claim->conversationId,
                        'turn_id' => $claim->turnId, 'call_id' => $call->id, 'request_hash' => $requestHash,
                        'result_hash' => CanonicalJson::hash($result->toArray()), 'raw_calls' => $raw];
                    $seal = hash_hmac('sha256', CanonicalJson::hash($evidence), (string) config('app.key'));
                    DB::table('c02_responder_raw_evidence')->insert(['stage_id' => $stageId, 'ai_call_id' => $call->id,
                        'payload_encrypted' => Crypt::encryptString(json_encode(['evidence' => $evidence, 'seal' => $seal], JSON_THROW_ON_ERROR)),
                        'created_at' => now()]);
                });
            if (($result->error['code'] ?? null) === 'in_progress') {
                return 'in_progress';
            }

            if ($result->costMicros === null) {
                return 'uncertain';
            }

            return DB::table('c02_responder_raw_evidence')->where('stage_id', $stageId)
                ->where('ai_call_id', $result->aiCallId)->exists() ? 'completed' : 'raw_unavailable';
        } catch (Throwable) {
            throw new LogicException('Protected responder stage could not be completed.');
        }
    }

    /** Settle only an already saved, signed fake responder result; never run tools or a driver. */
    public function settleSavedFakeResponder(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql'
                || DB::connection()->transactionLevel() !== 0) {
                throw new LogicException;
            }

            return DB::transaction(function () use ($http, $claim, $stageId): string {
                // The stage load verifies the protected claim and current access,
                // then requires the HMAC of this late call/request binding.
                [$request, $context, , $callId, $requestHash] = app(SellerResponderStages::class)->load($http, $claim, $stageId);
                if (! is_string($callId) || ! is_string($requestHash)) {
                    throw new LogicException;
                }
                $body = app(ContactMasker::class)->request($request->toArray());
                $expected = app(RequestFingerprint::class)->hash($request, $context, $body, 'fake');
                $candidate = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                    ->where('brand_id', $context->brandId)->first(['id', 'budget_id']);
                if (! $candidate || ! hash_equals($expected, $requestHash)) {
                    throw new LogicException;
                }
                $budget = DB::table('ai_budgets')->where('id', $candidate->budget_id)->lockForUpdate()->first();
                $call = DB::table('ai_calls')->where('id', $callId)->lockForUpdate()->first();
                $models = app(ProviderCatalog::class)->models();
                $model = $models[$request->model] ?? null;
                if (! $budget || ! $call || $call->budget_id !== $budget->id || $budget->organization_id !== $context->organizationId
                    || $budget->brand_id !== $context->brandId || $budget->family !== 'seller'
                    || $call->organization_id !== $context->organizationId || $call->brand_id !== $context->brandId
                    || $call->family !== 'seller' || $call->purpose !== 'responder' || $call->object_type !== 'conversation'
                    || $call->object_id !== $claim->conversationId || $call->requested_model !== $request->model
                    || ! is_array($model) || ($model['status'] ?? null) !== 'active' || ($model['provider'] ?? null) !== $call->provider
                    || json_decode($call->data_classes, true, 512, JSON_THROW_ON_ERROR) !== $context->dataClasses
                    || (int) $call->attempt !== $context->attempt || $call->parent_call_id !== $context->parentCallId
                    || ! hash_equals($expected, $call->request_hash)
                    || ! hash_equals(hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key')), $call->idempotency_key_hash)
                    || ! is_string($call->result_encrypted)) {
                    throw new LogicException;
                }
                $stored = json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($stored)) {
                    throw new LogicException;
                }
                $this->assertResult($stored);
                $result = AiResult::fromArray($stored);
                if (! hash_equals($call->id, $result->aiCallId) || $result->costMicros === null) {
                    throw new LogicException;
                }
                if ($call->status === 'completed') {
                    if ($call->cost_micros === null || (int) $call->cost_micros !== $result->costMicros || $call->model !== $result->model
                        || $call->prompt_tokens === null || (int) $call->prompt_tokens !== $result->usage['promptTokens']
                        || $call->completion_tokens === null || (int) $call->completion_tokens !== $result->usage['completionTokens']
                        || $call->latency_ms === null || (int) $call->latency_ms !== $result->latencyMs || $call->error_code !== null
                        || (bool) $call->reserve_exceeded !== ((int) $call->reserved_micros > 0 && $result->costMicros > (int) $call->reserved_micros)) {
                        throw new LogicException;
                    }

                    return 'completed';
                }
                if ($call->status !== 'reserved' || $call->cost_micros !== null || $call->prompt_tokens !== null
                    || $call->completion_tokens !== null || $call->latency_ms !== null || $call->error_code !== null
                    || $call->model !== $request->model || $call->reserve_exceeded || (int) $budget->unresolved_count < 1
                    || (int) $budget->reserved_micros < (int) $call->reserved_micros) {
                    throw new LogicException;
                }
                $this->assertThresholdReady();
                $this->settle($call, $result);

                return 'completed';
            });
        } catch (Throwable) {
            throw new LogicException('Saved fake responder result cannot be settled.');
        }
    }

    /** Candidate only, inside the caller's transaction; Guard and delivery are separate. */
    public function readProtectedResponderLocked(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            [$request, $context, , $callId, $requestHash] = app(SellerResponderStages::class)->load($http, $claim, $stageId);
            if (! is_string($callId) || ! is_string($requestHash)) {
                throw new LogicException;
            }
            $body = app(ContactMasker::class)->request($request->toArray());
            $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
            $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->lockForUpdate()->first();
            if (! $call || $call->status !== 'completed' || ! hash_equals($expected, $requestHash)
                || ! hash_equals($expected, $call->request_hash)
                || ! hash_equals(hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key')), $call->idempotency_key_hash)) {
                throw new LogicException;
            }
            $result = $this->replay($call, $request->model);
            $this->assertResult($result->toArray());
            if ($result->costMicros === null || $call->cost_micros === null || (int) $call->cost_micros !== $result->costMicros
                || $call->model !== $result->model || $call->prompt_tokens === null || (int) $call->prompt_tokens !== $result->usage['promptTokens']
                || $call->completion_tokens === null || (int) $call->completion_tokens !== $result->usage['completionTokens']
                || $call->latency_ms === null || (int) $call->latency_ms !== $result->latencyMs) {
                throw new LogicException;
            }

            // A tool signal carries no names or arguments and never executes a tool.
            return app(ResponderResult::class)->interpret($result);
        } catch (Throwable) {
            throw new LogicException('Protected responder result unavailable.');
        }
    }

    /** Read source data only under the caller's transaction; the returned array never authorizes an action. */
    public function readProtectedToolCallLocked(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId, int $index): array
    {
        try {
            // This existing read validates the session, source, current turn,
            // stage binding, saved result and accounting while holding locks.
            $signal = $this->readProtectedResponderLocked($http, $claim, $stageId);
            if (($signal['status'] ?? null) !== 'tool_calls' || $index < 0) {
                throw new LogicException;
            }
            [$request, $context, , $callId] = app(SellerResponderStages::class)->load($http, $claim, $stageId);
            if (! is_string($callId)) {
                throw new LogicException;
            }
            $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->lockForUpdate()->first();
            if (! $call) {
                throw new LogicException;
            }
            $result = $this->replay($call, $request->model);
            $this->assertResult($result->toArray());
            if ($result->error !== null || ! isset($result->toolCalls[$index])) {
                throw new LogicException;
            }
            $selected = $result->toolCalls[$index];
            $ids = [];
            foreach ($result->toolCalls as $tool) {
                if ($tool['id'] === '' || isset($ids[$tool['id']])) {
                    throw new LogicException;
                }
                $ids[$tool['id']] = true;
            }
            $offered = array_column(array_column($request->tools, 'function'), 'name');
            if (! in_array($selected['name'], $offered, true)
                || ! app(ToolArguments::class)->check($selected['name'], $selected['arguments'])['valid']) {
                throw new LogicException;
            }

            return ['id' => $selected['id'], 'name' => $selected['name'], 'arguments' => $selected['arguments']];
        } catch (Throwable) {
            throw new LogicException('Protected tool call unavailable.');
        }
    }

    /** Source evidence only; this never authorizes a tool or any external effect. */
    public function readProtectedRawToolCallLocked(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId, int $index): array
    {
        try {
            $selected = $this->readProtectedToolCallLocked($http, $claim, $stageId, $index);
            [$request, $context, , $callId, $requestHash] = app(SellerResponderStages::class)->load($http, $claim, $stageId);
            $call = DB::table('ai_calls')->where('id', $callId)->lockForUpdate()->first();
            $row = DB::table('c02_responder_raw_evidence')->where('stage_id', $stageId)->where('ai_call_id', $callId)->lockForUpdate()->first();
            if (! $call || ! $row || ! is_string($requestHash)) {
                throw new LogicException;
            }
            $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true, 512, JSON_THROW_ON_ERROR);
            $evidence = $payload['evidence'] ?? null;
            $seal = $payload['seal'] ?? null;
            if (! is_array($evidence) || ! is_string($seal)
                || ! hash_equals(hash_hmac('sha256', CanonicalJson::hash($evidence), (string) config('app.key')), $seal)
                || ($evidence['stage_id'] ?? null) !== $stageId || ($evidence['organization_id'] ?? null) !== $context->organizationId
                || ($evidence['brand_id'] ?? null) !== $context->brandId || ($evidence['conversation_id'] ?? null) !== $claim->conversationId
                || ($evidence['turn_id'] ?? null) !== $claim->turnId || ($evidence['call_id'] ?? null) !== $callId
                || ($evidence['request_hash'] ?? null) !== $requestHash
                || ($evidence['result_hash'] ?? null) !== CanonicalJson::hash($this->replay($call, $request->model)->toArray())) {
                throw new LogicException;
            }
            $raw = $evidence['raw_calls'][$index] ?? null;
            if (! is_array($raw) || ($raw['id'] ?? null) !== $selected['id'] || ($raw['name'] ?? null) !== $selected['name']
                || ! is_string($raw['arguments_json'] ?? null) || ! app(RawToolArguments::class)->accepts($raw['arguments_json'])
                || json_decode($raw['arguments_json'], true, 22, JSON_THROW_ON_ERROR) !== $selected['arguments']) {
                throw new LogicException;
            }

            return $raw;
        } catch (Throwable) {
            throw new LogicException('Protected raw tool call unavailable.');
        }
    }

    /** Internal extractor stage; a reference never replaces session authorization. */
    public function completeProtectedExtractor(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                throw new LogicException;
            }
            $stages = app(SellerExtractorStages::class);
            [$request, $context, $digest] = $stages->load($http, $claim, $stageId);
            $result = $this->completeInternal($request, $context,
                fn () => $stages->lockForGateway($http, $claim, $stageId, $digest),
                fn (object $call, string $hash) => $stages->bindCall($http, $claim, $stageId, $call, $hash));
            if (($result->error['code'] ?? null) === 'in_progress') {
                return 'in_progress';
            }

            return $result->costMicros === null ? 'uncertain' : 'completed';
        } catch (Throwable) {
            throw new LogicException('Protected extractor stage could not be completed.');
        }
    }

    /** Settle an already saved fake result only; this never invokes a driver. */
    public function settleSavedFakeExtractor(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql'
                || DB::connection()->transactionLevel() !== 0) {
                throw new LogicException;
            }

            return DB::transaction(function () use ($http, $claim, $stageId): string {
                // load rechecks the session lease, tenant, permissions, fresh
                // protected claim, stage seal and any reservation evidence.
                [$request, $context, , $callId, $requestHash] = app(SellerExtractorStages::class)->load($http, $claim, $stageId);
                if (! is_string($callId) || ! is_string($requestHash) || $request->tools !== [] || $request->toolChoice !== null
                    || ! DB::table('c02_extractor_effect_reservations')->where('extractor_stage_id', $stageId)->where('ai_call_id', $callId)->exists()) {
                    throw new LogicException;
                }
                $body = app(ContactMasker::class)->request($request->toArray());
                $expected = app(RequestFingerprint::class)->hash($request, $context, $body, 'fake');
                $candidate = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                    ->where('brand_id', $context->brandId)->first(['id', 'budget_id']);
                if (! $candidate || ! hash_equals($expected, $requestHash)) {
                    throw new LogicException;
                }
                // Keep the Gateway's budget-before-call lock order.
                $budget = DB::table('ai_budgets')->where('id', $candidate->budget_id)->lockForUpdate()->first();
                $call = DB::table('ai_calls')->where('id', $callId)->lockForUpdate()->first();
                $models = app(ProviderCatalog::class)->models();
                $model = $models[$request->model] ?? null;
                if (! $budget || ! $call || $call->budget_id !== $budget->id || $budget->organization_id !== $context->organizationId
                    || $budget->brand_id !== $context->brandId || $budget->family !== 'seller'
                    || $call->organization_id !== $context->organizationId || $call->brand_id !== $context->brandId
                    || $call->family !== 'seller' || $call->purpose !== 'extractor' || $call->object_type !== 'conversation'
                    || $call->object_id !== $claim->conversationId || $call->requested_model !== $request->model
                    || ! is_array($model) || ($model['status'] ?? null) !== 'active' || ($model['provider'] ?? null) !== $call->provider
                    || json_decode($call->data_classes, true, 512, JSON_THROW_ON_ERROR) !== $context->dataClasses
                    || (int) $call->attempt !== $context->attempt || $call->parent_call_id !== $context->parentCallId
                    || ! hash_equals($expected, $call->request_hash)
                    || ! hash_equals(hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key')), $call->idempotency_key_hash)
                    || ! is_string($call->result_encrypted)) {
                    throw new LogicException;
                }
                $stored = json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($stored)) {
                    throw new LogicException;
                }
                $this->assertResult($stored);
                $result = AiResult::fromArray($stored);
                if (! hash_equals($call->id, $result->aiCallId) || $result->costMicros === null) {
                    throw new LogicException;
                }
                if ($call->status === 'completed') {
                    if ($call->cost_micros === null || (int) $call->cost_micros !== $result->costMicros || $call->model !== $result->model
                        || $call->prompt_tokens === null || (int) $call->prompt_tokens !== $result->usage['promptTokens']
                        || $call->completion_tokens === null || (int) $call->completion_tokens !== $result->usage['completionTokens']
                        || $call->latency_ms === null || (int) $call->latency_ms !== $result->latencyMs || $call->error_code !== null
                        || (bool) $call->reserve_exceeded !== ((int) $call->reserved_micros > 0 && $result->costMicros > (int) $call->reserved_micros)) {
                        throw new LogicException;
                    }

                    return 'completed';
                }
                if ($call->status !== 'reserved' || $call->cost_micros !== null || $call->prompt_tokens !== null
                    || $call->completion_tokens !== null || $call->latency_ms !== null || $call->error_code !== null
                    || $call->model !== $request->model || $call->reserve_exceeded || (int) $budget->unresolved_count < 1
                    || (int) $budget->reserved_micros < (int) $call->reserved_micros) {
                    throw new LogicException;
                }
                $this->assertThresholdReady();
                $this->settle($call, $result);

                return 'completed';
            });
        } catch (Throwable) {
            throw new LogicException('Saved fake extractor result cannot be settled.');
        }
    }

    /** Read only inside the caller's finalization transaction; this does not authorize effects. */
    public function readProtectedExtractorLocked(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            [$request, $context, , $callId, $requestHash, $state, $message, $engine] = app(SellerExtractorStages::class)->load($http, $claim, $stageId);
            if (! is_string($callId) || ! is_string($requestHash) || $request->tools !== [] || $request->toolChoice !== null) {
                throw new LogicException;
            }
            $body = app(ContactMasker::class)->request($request->toArray());
            $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
            $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->lockForUpdate()->first();
            if (! $call || $call->status !== 'completed' || ! hash_equals($expected, $requestHash)
                || ! hash_equals($expected, $call->request_hash)
                || ! hash_equals(hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key')), $call->idempotency_key_hash)) {
                throw new LogicException;
            }
            $result = $this->replay($call, $request->model);
            $this->assertResult($result->toArray());
            if ($result->toolCalls !== [] || $result->costMicros === null || $call->cost_micros === null
                || (int) $call->cost_micros !== $result->costMicros || $call->model !== $result->model
                || $call->prompt_tokens === null || (int) $call->prompt_tokens !== $result->usage['promptTokens']
                || $call->completion_tokens === null || (int) $call->completion_tokens !== $result->usage['completionTokens']
                || $call->latency_ms === null || (int) $call->latency_ms !== $result->latencyMs) {
                throw new LogicException;
            }

            return $engine->interpretResult($state, $message, $result);
        } catch (Throwable) {
            throw new LogicException('Protected extractor result unavailable.');
        }
    }

    /** Internal summary stage; the reference and claim do not replace session authorization. */
    public function completeProtectedSummary(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): string
    {
        try {
            // This first protected path is deliberately limited to the fake driver
            // until runtime, plan-revocation and external-effect ownership are joined.
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                throw new LogicException;
            }
            $stages = app(SellerSummaryStages::class);
            [$request, $context, $digest] = $stages->load($http, $claim, $stageId);

            $result = $this->completeInternal($request, $context,
                fn () => $stages->lockForGateway($http, $claim, $stageId, $digest),
                fn (object $call, string $hash) => $stages->bindCall($http, $claim, $stageId, $call, $hash));

            // Provider data is only available through readProtectedSummaryLocked,
            // which rechecks authority and freshness after the external boundary.
            if (($result->error['code'] ?? null) === 'in_progress') {
                return 'in_progress';
            }

            return $result->costMicros === null ? 'uncertain' : 'completed';
        } catch (Throwable) {
            throw new LogicException('Protected summary stage could not be completed.');
        }
    }

    /** Read under the caller's finalization transaction; this does not authorize effects. */
    public function readProtectedSummaryLocked(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] string $stageId): AiResult
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            [$request, $context, , $callId, $requestHash] = app(SellerSummaryStages::class)->load($http, $claim, $stageId);
            if (! is_string($callId) || ! is_string($requestHash) || $request->tools !== [] || $request->toolChoice !== null) {
                throw new LogicException;
            }
            $body = app(ContactMasker::class)->request($request->toArray());
            $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
            $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->lockForUpdate()->first();
            if (! $call || $call->status !== 'completed' || ! hash_equals($expected, $requestHash)
                || ! hash_equals($expected, $call->request_hash)
                || ! hash_equals(hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key')), $call->idempotency_key_hash)) {
                throw new LogicException;
            }

            $result = $this->replay($call, $request->model);
            // A summary request never authorizes tools. Even an authenticated,
            // successfully stored provider response cannot invent one.
            if ($result->toolCalls !== []) {
                throw new LogicException;
            }

            return $result;
        } catch (Throwable) {
            throw new LogicException('Protected summary result unavailable.');
        }
    }

    /** Complete a server-built API extractor request in fake mode. */
    public function completeApiFollowupExtractor(#[\SensitiveParameter] Request $http, TenantContext $tenant, TurnClaim $claim): AiResult
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::transactionLevel() !== 0) {
            throw new LogicException('API follow-up unavailable.');
        }
        try {
            [$input, $context] = DB::transaction(fn () => app(ApiFollowupInput::class)->extractorLocked($http, $tenant, $claim));
            $expected = CanonicalJson::hash($input->toArray());
            $assertSource = function () use ($http, $tenant, $claim, $context, $expected): void {
                [$current, $currentContext] = app(ApiFollowupInput::class)->extractorLocked($http, $tenant, $claim);
                if (! hash_equals($expected, CanonicalJson::hash($current->toArray()))
                    || $currentContext->idempotencyKey !== $context->idempotencyKey) {
                    throw new LogicException;
                }
            };

            return $this->completeInternal($input, $context, $assertSource);
        } catch (Throwable) {
            throw new LogicException('API follow-up unavailable.');
        }
    }

    public function readApiFollowupExtractorLocked(#[\SensitiveParameter] Request $http, TenantContext $tenant, TurnClaim $claim): AiResult
    {
        if (DB::transactionLevel() < 1 || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('API follow-up unavailable.');
        }
        [$input, $context] = app(ApiFollowupInput::class)->extractorLocked($http, $tenant, $claim);
        $body = app(ContactMasker::class)->request($input->toArray());
        $expected = app(RequestFingerprint::class)->hash($input, $context, $body, 'fake');
        $key = hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key'));
        $call = DB::table('ai_calls')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('idempotency_key_hash', $key)->lockForUpdate()->first();
        if (! $call || $call->status !== 'completed' || ! hash_equals($expected, $call->request_hash)
            || ! is_string($call->result_encrypted)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $result = AiResult::fromArray(json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR));
        if ($result->aiCallId !== $call->id || ! $result->ok() || $result->costMicros === null) {
            throw new LogicException('API follow-up unavailable.');
        }

        return $result;
    }

    public function completeApiFollowupResponder(#[\SensitiveParameter] Request $http, TenantContext $tenant, TurnClaim $claim): AiResult
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::transactionLevel() !== 0) {
            throw new LogicException('API follow-up unavailable.');
        }
        try {
            [$input, $context] = DB::transaction(fn () => app(ApiFollowupInput::class)->responderLocked($http, $tenant, $claim));
            $expected = CanonicalJson::hash($input->toArray());
            $assertSource = function () use ($http, $tenant, $claim, $context, $expected): void {
                [$current, $currentContext] = app(ApiFollowupInput::class)->responderLocked($http, $tenant, $claim);
                if (! hash_equals($expected, CanonicalJson::hash($current->toArray()))
                    || $currentContext->idempotencyKey !== $context->idempotencyKey) {
                    throw new LogicException;
                }
            };

            return $this->completeInternal($input, $context, $assertSource);
        } catch (Throwable) {
            throw new LogicException('API follow-up unavailable.');
        }
    }

    public function readApiFollowupResponderLocked(#[\SensitiveParameter] Request $http, TenantContext $tenant, TurnClaim $claim): AiResult
    {
        if (DB::transactionLevel() < 1 || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('API follow-up unavailable.');
        }
        [$input, $context] = app(ApiFollowupInput::class)->responderLocked($http, $tenant, $claim);
        $body = app(ContactMasker::class)->request($input->toArray());
        $expected = app(RequestFingerprint::class)->hash($input, $context, $body, 'fake');
        $key = hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key'));
        $call = DB::table('ai_calls')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('idempotency_key_hash', $key)->lockForUpdate()->first();
        if (! $call || $call->status !== 'completed' || ! hash_equals($expected, $call->request_hash)
            || ! is_string($call->result_encrypted)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $result = AiResult::fromArray(json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR));
        if ($result->aiCallId !== $call->id || ! $result->ok() || $result->costMicros === null) {
            throw new LogicException('API follow-up unavailable.');
        }

        return $result;
    }

    /** Complete a server-built proactive API request in fake mode. */
    public function completeApiOpening(#[\SensitiveParameter] Request $http, TenantContext $tenant, string $leadId): AiResult
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::transactionLevel() !== 0) {
            throw new LogicException('API opening unavailable.');
        }
        try {
            [$input, $context] = DB::transaction(fn () => app(ApiOpeningInput::class)->buildLocked($http, $tenant, $leadId));
            $expected = CanonicalJson::hash($input->toArray());
            $assertSource = function () use ($http, $tenant, $leadId, $context, $expected): void {
                [$current, $currentContext] = app(ApiOpeningInput::class)->buildLocked($http, $tenant, $leadId);
                if (! hash_equals($expected, CanonicalJson::hash($current->toArray()))
                    || $currentContext->idempotencyKey !== $context->idempotencyKey) {
                    throw new LogicException;
                }
            };

            return $this->completeInternal($input, $context, $assertSource);
        } catch (Throwable) {
            throw new LogicException('API opening unavailable.');
        }
    }

    /** Complete the server-built extractor request for an assigned manager in fake mode. */
    public function completeManagerSuggestionExtractor(#[\SensitiveParameter] Request $http, string $dialog, #[\SensitiveParameter] string $key): AiResult
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Manager suggestion extractor unavailable.');
        }
        try {
            [$input, $source] = app(ManagerSuggestionExtractorInput::class)->build($http, $dialog, $key);
            $tenant = app(TenantContext::class);
            $context = new AiContext($tenant->organizationId, $tenant->brandId, 'extractor', 'seller', 'conversation', $dialog,
                'manager-suggestion-extractor-'.hash_hmac('sha256', $key.CanonicalJson::hash($source), (string) config('app.key')),
                ['conversation_content', 'client_profile', 'knowledge_internal']);
            $expected = CanonicalJson::hash($input->toArray());
            $assertSource = function () use ($http, $dialog, $key, $source, $expected): void {
                [$current, $currentSource] = app(ManagerSuggestionExtractorInput::class)->build($http, $dialog, $key);
                if ($currentSource !== $source || ! hash_equals($expected, CanonicalJson::hash($current->toArray()))) {
                    throw new LogicException;
                }
            };
            $result = $this->completeInternal($input, $context, $assertSource);
            $assertSource();

            return $result;
        } catch (Throwable) {
            throw new LogicException('Manager suggestion extractor unavailable.');
        }
    }

    public function complete(#[\SensitiveParameter] AiRequest $request, #[\SensitiveParameter] AiContext $context): AiResult
    {
        try {
            return $this->completeInternal($request, $context);
        } catch (Rejected $error) {
            // Preserve the retry classification without exposing driver arguments.
            $message = match (true) {
                $error->getMessage() === 'Previous AI attempt was confirmed unbilled; an explicit new attempt is required.' => $error->getMessage(),
                $error->getMessage() === 'Previous AI attempt was billed without a usable result; an explicit new attempt is required.' => $error->getMessage(),
                config('runtime.ai_driver', 'fake') === 'fake' && str_starts_with($error->getMessage(), 'AI fixture missing') => 'AI fixture missing for the normalized request.',
                default => 'AI provider rejected the request.',
            };
            throw new Rejected($message);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException(GatewayErrorMessage::sanitize($error->getMessage()));
        } catch (LogicException $error) {
            throw new LogicException(GatewayErrorMessage::sanitize($error->getMessage()));
        } catch (Throwable) {
            throw new \RuntimeException('AI request could not be safely completed.');
        }
    }

    private function completeInternal(#[\SensitiveParameter] AiRequest $request, #[\SensitiveParameter] AiContext $context, ?\Closure $beforeReserve = null, ?\Closure $afterReserve = null, ?\Closure $captureRaw = null): AiResult
    {
        // An outer rollback must never undo accounting for an already sent request.
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('AI calls cannot run inside a database transaction.');
        }
        try {
            app(SecretBoundary::class)->assertSafe(get_object_vars($context));
            app(ContactBoundary::class)->assertSafe(['messages' => [['role' => 'user', 'content' => [
                'purpose' => $context->purpose, 'family' => $context->family,
                'object_type' => $context->objectType, 'data_classes' => $context->dataClasses,
            ]]]]);
        } catch (Throwable) {
            throw new InvalidArgumentException('AI data could not be safely prepared.');
        }
        $this->assertTenantAndObject($context);
        // Validate encryption before any paid call; idempotent results are stored encrypted.
        app('encrypter');
        $this->assertThresholdReady();
        $models = app(ProviderCatalog::class)->models();
        $model = $models[$request->model] ?? null;
        if (! is_array($model) || ($model['status'] ?? null) !== 'active' || ! is_string($model['provider'] ?? null)) {
            throw new InvalidArgumentException('The requested AI provider/model is not active.');
        }
        $driverName = config('runtime.ai_driver', 'fake');
        if (! in_array($driverName, ['fake', 'record', 'openrouter'], true)) {
            throw new LogicException('Unsupported AI driver.');
        }
        if ($driverName === 'record' && ! app()->environment('local', 'testing')) {
            throw new LogicException('Recording is only allowed in local/testing.');
        }
        if ($driverName !== 'fake') {
            $this->assertLiveAllowed($context);
        }
        try {
            app(SecretBoundary::class)->assertSafe(SchemaObjects::forInspection($request->toArray()));
            $body = app(ContactMasker::class)->request($request->toArray());
            $inspectionBody = SchemaObjects::forInspection($body);
            app(ContactBoundary::class)->assertSafe($inspectionBody);
            app(SecretBoundary::class)->assertSafe($inspectionBody);
        } catch (Throwable) {
            // Do not chain exceptions whose stack may contain unmasked input.
            throw new InvalidArgumentException('AI data could not be safely prepared.');
        }
        $requestHash = app(RequestFingerprint::class)->hash($request, $context, $body, $driverName);
        $wireBytes = $driverName === 'fake' ? null : strlen(json_encode(
            app(OpenRouterDriver::class)->wireBody($body), JSON_THROW_ON_ERROR,
        ));
        [$call, $existing] = $this->reserve($request, $context, $model['provider'], $requestHash, $wireBytes, $beforeReserve, $afterReserve);
        if ($existing) {
            return $this->replay($call, $request->model);
        }
        $driver = $this->driver($driverName);
        try {
            $raw = null;
            if ($captureRaw !== null) {
                if ($driverName !== 'fake' || ! $driver instanceof FakeDriver) {
                    throw new LogicException;
                }
                $captured = $driver->completeWithRawToolCalls($body);
                $data = $captured['result'];
                $raw = $captured['raw_tool_calls'];
            } else {
                $data = $driver->complete($body);
            }
            $this->assertResult($data);
            $empty = ($data['content'] === null || trim($data['content']) === '') && $data['toolCalls'] === [];
            $result = new AiResult($data['content'], $data['toolCalls'], $data['usage'], $data['model'], $data['latencyMs'], $data['costMicros'], $call->id, $empty ? ['code' => 'empty_response', 'retryable' => false] : null);
            // Preserve the normalized provider response before accounting. A crash after
            // this write cannot cause a paid request to be sent again or its cost lost.
            $persist = function () use ($call, $result, $captureRaw, $raw, $data): void {
                // Keep stage/session locks ahead of the ai_calls row, as in protected reads.
                if ($captureRaw !== null) {
                    $captureRaw($call, $result, $raw);
                }
                $saved = DB::table('ai_calls')->where('id', $call->id)->where('status', 'reserved')->update([
                    'result_encrypted' => Crypt::encryptString(json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'provider_generation_id' => $data['providerGenerationId'] ?? null,
                    'updated_at' => now(),
                ]);
                if ($saved !== 1) {
                    throw new LogicException('AI call state changed before response persistence.');
                }
            };
            if ($captureRaw !== null) {
                DB::transaction($persist);
            } else {
                $persist();
            }
            if ($data['costMicros'] === null) {
                return $this->uncertain($call, $request->model, 'cost_unknown');
            }
            $this->settle($call, $result);
            if ($driverName === 'record') {
                $this->record($body, $data);
            }

            return $result;
        } catch (Rejected $e) {
            $this->reject($call, 'provider_rejected');
            // Missing fixtures are test errors, never successful empty output.
            throw $e;
        } catch (Throwable $e) {
            if ($e instanceof Uncertain && $e->providerGenerationId !== null) {
                DB::table('ai_calls')->where('id', $call->id)->where('status', 'reserved')->whereNull('provider_generation_id')
                    ->update(['provider_generation_id' => $e->providerGenerationId, 'updated_at' => now()]);
            }
            $this->uncertain($call, $request->model, 'provider_uncertain');
            if ($e instanceof Uncertain) {
                return new AiResult(null, [], ['promptTokens' => null, 'completionTokens' => null], $request->model, 0, null, $call->id, ['code' => 'provider_uncertain', 'retryable' => false]);
            }
            throw $e;
        }
    }

    private function reserve(#[\SensitiveParameter] AiRequest $request, #[\SensitiveParameter] AiContext $context, string $provider, string $requestHash, ?int $wireBytes, ?\Closure $beforeReserve = null, ?\Closure $afterReserve = null): array
    {
        return DB::transaction(function () use ($request, $context, $provider, $requestHash, $wireBytes, $beforeReserve, $afterReserve): array {
            if ($beforeReserve !== null) {
                $beforeReserve();
            }
            if ($beforeReserve === null && (str_starts_with($context->idempotencyKey, 'manager-suggestion-extractor-')
                || str_starts_with($context->idempotencyKey, 'api-opening-')
                || str_starts_with($context->idempotencyKey, 'api-followup-extractor-')
                || str_starts_with($context->idempotencyKey, 'api-followup-responder-'))) {
                throw new LogicException('Protected AI call requires protected reading.');
            }
            // Prepared extractor inputs cannot be dispatched through public
            // complete(), including before their first protected reservation.
            if ($beforeReserve === null && str_starts_with($context->idempotencyKey, 'c02-extractor-') && Schema::hasTable('c02_extractor_stages')) {
                $stageId = substr($context->idempotencyKey, strlen('c02-extractor-'));
                if (Str::isUlid($stageId) && DB::table('c02_extractor_stages')->where('id', $stageId)
                    ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('conversation_id', $context->objectId)->lockForUpdate()->exists()) {
                    throw new LogicException('Protected AI call requires protected reading.');
                }
            }
            if ($beforeReserve === null && str_starts_with($context->idempotencyKey, 'c02-responder-') && Schema::hasTable('c02_responder_stages')) {
                $stageId = substr($context->idempotencyKey, strlen('c02-responder-'));
                if (Str::isUlid($stageId) && DB::table('c02_responder_stages')->where('id', $stageId)
                    ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('conversation_id', $context->objectId)->lockForUpdate()->exists()) {
                    throw new LogicException('Protected AI call requires protected reading.');
                }
            }
            if ($beforeReserve === null && str_starts_with($context->idempotencyKey, 'c02-guard-') && Schema::hasTable('c02_guard_stages')) {
                $stageId = substr($context->idempotencyKey, strlen('c02-guard-'));
                if (Str::isUlid($stageId) && DB::table('c02_guard_stages')->where('id', $stageId)
                    ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('conversation_id', $context->objectId)->lockForUpdate()->exists()) {
                    throw new LogicException('Protected AI call requires protected reading.');
                }
            }
            $keyHash = hash_hmac('sha256', $context->idempotencyKey, (string) config('app.key'));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$context->organizationId.':'.$context->brandId.':'.$keyHash]);
            $existing = DB::table('ai_calls')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new LogicException('AI idempotency key reused with different request or context.');
                }

                // A copied request or idempotency key cannot bypass the
                // protected transactional read of an already bound result.
                if ($beforeReserve === null && ((Schema::hasTable('c02_ai_stages')
                    && DB::table('c02_ai_stages')->where('ai_call_id', $existing->id)->exists())
                    || (Schema::hasTable('c02_extractor_stages')
                    && DB::table('c02_extractor_stages')->where('ai_call_id', $existing->id)->exists())
                    || (Schema::hasTable('c02_responder_stages')
                    && DB::table('c02_responder_stages')->where('ai_call_id', $existing->id)->exists())
                    || (Schema::hasTable('c02_guard_stages')
                    && DB::table('c02_guard_stages')->where('ai_call_id', $existing->id)->exists()))) {
                    throw new LogicException('Protected AI call requires protected reading.');
                }
                if ($afterReserve !== null) {
                    $afterReserve($existing, $requestHash);
                }

                return [$existing, true];
            }
            $period = now('UTC')->startOfMonth()->toDateString();
            $budgetId = (string) Str::ulid();
            DB::table('ai_budgets')->insertOrIgnore(['id' => $budgetId, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'family' => $context->family, 'period' => $period, 'spent_micros' => 0, 'reserved_micros' => 0, 'unresolved_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $budget = DB::table('ai_budgets')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('family', $context->family)->where('period', $period)->lockForUpdate()->first();
            if (($context->attempt === 1) !== ($context->parentCallId === null)) {
                throw new InvalidArgumentException('AI attempt requires a matching parent.');
            }
            if ($context->parentCallId !== null) {
                $parent = DB::table('ai_calls')->where('id', $context->parentCallId)->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('object_type', $context->objectType)->where('object_id', $context->objectId)->lockForUpdate()->first();
                if (! $parent || $parent->status !== 'failed' || (int) $parent->attempt + 1 !== $context->attempt) {
                    throw new LogicException('AI parent attempt cannot be retried.');
                }
                if (DB::table('ai_calls')->where('parent_call_id', $parent->id)->exists()) {
                    throw new LogicException('AI parent attempt already has a retry.');
                }
            }
            if ($budget->limit_units !== null) {
                throw new LogicException('AI unit budget requires an approved conversion rule.');
            }
            if ($budget->reservation_breached) {
                throw new LogicException('AI reservation was exceeded and requires operator review.');
            }
            $bounds = config('features.E0-05.defaults.reservation_micros', []);
            $bound = $bounds[$request->model] ?? null;
            if ($budget->limit_micros !== null) {
                if (config('runtime.ai_driver', 'fake') !== 'fake') {
                    $limits = config('features.E0-05.defaults.reservation_request_limits', [])[$request->model] ?? null;
                    if (! is_array($limits) || ! is_int($limits['max_input_bytes'] ?? null) || $limits['max_input_bytes'] < 1 || ! is_int($limits['max_output_tokens'] ?? null) || $limits['max_output_tokens'] < 1) {
                        throw new LogicException('A configured live budget requires bounded request sizes.');
                    }
                    if ($wireBytes === null || $wireBytes > $limits['max_input_bytes'] || ($request->maxTokens ?? 700) > $limits['max_output_tokens']) {
                        throw new LogicException('AI request exceeds the configured reservation bounds.');
                    }
                }
                if (! is_int($bound) || $bound <= 0) {
                    throw new LogicException('A configured budget requires a model reservation bound.');
                }
                if (DB::table('ai_calls')->where('budget_id', $budget->id)->where(function ($query): void {
                    $query->where('status', 'uncertain')->orWhere(fn ($pending) => $pending->where('status', 'reserved')->where('reserved_micros', 0));
                })->exists()) {
                    throw new LogicException('An uncertain or unreserved AI charge requires reconciliation before further spending.');
                }
                if ($bound > (int) $budget->limit_micros - (int) $budget->spent_micros - (int) $budget->reserved_micros) {
                    throw new LogicException('AI budget exhausted.');
                }
            }
            $reserved = is_int($bound) && $bound > 0 ? $bound : 0;
            $id = (string) Str::ulid();
            DB::table('ai_calls')->insert(['id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'budget_id' => $budget->id, 'idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash, 'family' => $context->family, 'purpose' => $context->purpose, 'provider' => $provider, 'requested_model' => $request->model, 'model' => $request->model, 'object_type' => $context->objectType, 'object_id' => $context->objectId, 'data_classes' => json_encode($context->dataClasses, JSON_THROW_ON_ERROR), 'attempt' => $context->attempt, 'parent_call_id' => $context->parentCallId, 'status' => 'reserved', 'reserved_micros' => $reserved, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_budgets')->where('id', $budget->id)->increment('reserved_micros', $reserved, ['unresolved_count' => (int) $budget->unresolved_count + 1, 'updated_at' => now()]);

            $call = DB::table('ai_calls')->where('id', $id)->first();
            if ($afterReserve !== null) {
                $afterReserve($call, $requestHash);
            }

            return [$call, false];
        });
    }

    private function settle(object $call, #[\SensitiveParameter] AiResult $result): void
    {
        DB::transaction(function () use ($call, $result): void {
            $budget = DB::table('ai_budgets')->where('id', $call->budget_id)->lockForUpdate()->first();
            $current = DB::table('ai_calls')->where('id', $call->id)->lockForUpdate()->first();
            if (! $budget || ! $current || $current->status !== 'reserved') {
                throw new LogicException('AI reservation cannot be settled.');
            }
            DB::table('ai_budgets')->where('id', $budget->id)->update(['reserved_micros' => (int) $budget->reserved_micros - (int) $current->reserved_micros, 'spent_micros' => (int) $budget->spent_micros + $result->costMicros, 'unresolved_count' => (int) $budget->unresolved_count - 1, 'reservation_breached' => (bool) $budget->reservation_breached || ((int) $current->reserved_micros > 0 && $result->costMicros > (int) $current->reserved_micros), 'updated_at' => now()]);
            DB::table('ai_calls')->where('id', $call->id)->update(['status' => 'completed', 'model' => $result->model, 'cost_micros' => $result->costMicros, 'reserve_exceeded' => (int) $current->reserved_micros > 0 && $result->costMicros > (int) $current->reserved_micros, 'prompt_tokens' => $result->usage['promptTokens'], 'completion_tokens' => $result->usage['completionTokens'], 'latency_ms' => $result->latencyMs, 'result_encrypted' => Crypt::encryptString(json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
            $this->attribute($current, $result);
            $this->notifyThreshold($budget, (int) $budget->spent_micros + $result->costMicros);
        });
    }

    /** Reserved for a future authorized workflow that verifies provider evidence. */
    public function reconcileSuccess(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] AiResult $result, #[\SensitiveParameter] string $evidence): void
    {
        try {
            $this->reconcileSuccessInternal($tenant, $result, $evidence);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException(GatewayErrorMessage::sanitize($error->getMessage()));
        } catch (LogicException $error) {
            throw new LogicException(GatewayErrorMessage::sanitize($error->getMessage()));
        } catch (Throwable) {
            throw new \RuntimeException('AI reconciliation could not be safely completed.');
        }
    }

    private function reconcileSuccessInternal(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] AiResult $result, #[\SensitiveParameter] string $evidence): void
    {
        $this->assertThresholdReady();
        $this->reconciliationCall($tenant, $result->aiCallId, $evidence);
        if (($result->error !== null && ($result->error['code'] ?? null) !== 'empty_response') || $result->costMicros === null) {
            throw new InvalidArgumentException('Confirmed result and cost are required.');
        }
        $this->assertResult($result->toArray());
        // A caller-supplied result and receipt string are not provider verification.
        throw new LogicException('Successful reconciliation requires a verified provider evidence workflow.');
    }

    /** Unbilled recovery stays closed until provider evidence can be verified. */
    public function reconcileUnbilled(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] string $callId, #[\SensitiveParameter] string $evidence): void
    {
        try {
            $this->reconcileUnbilledInternal($tenant, $callId, $evidence);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException(GatewayErrorMessage::sanitize($error->getMessage()));
        } catch (LogicException $error) {
            throw new LogicException(GatewayErrorMessage::sanitize($error->getMessage()));
        } catch (Throwable) {
            throw new \RuntimeException('AI reconciliation could not be safely completed.');
        }
    }

    private function reconcileUnbilledInternal(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] string $callId, #[\SensitiveParameter] string $evidence): void
    {
        $this->reconciliationCall($tenant, $callId, $evidence);
        // A free-form string is not proof that a provider did not charge.
        throw new LogicException('Unbilled reconciliation requires a verified provider evidence workflow.');
    }

    private function reconciliationCall(#[\SensitiveParameter] TenantContext $tenant, #[\SensitiveParameter] string $callId, #[\SensitiveParameter] string $evidence): object
    {
        $current = app(TenantContext::class);
        if ($tenant->organizationId === null || $tenant->brandId === null || $current->organizationId !== $tenant->organizationId || $current->brandId !== $tenant->brandId || trim($evidence) === '' || strlen($evidence) > 2000) {
            throw new InvalidArgumentException('Active tenant and evidence are required.');
        }
        $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        if (! $call) {
            throw new LogicException('AI call not found.');
        }

        return $call;
    }

    private function reject(object $call, string $code): void
    {
        DB::transaction(function () use ($call, $code): void {
            $budget = DB::table('ai_budgets')->where('id', $call->budget_id)->lockForUpdate()->first();
            $current = DB::table('ai_calls')->where('id', $call->id)->lockForUpdate()->first();
            if (! $budget || ! $current || $current->status !== 'reserved') {
                return;
            }
            DB::table('ai_budgets')->where('id', $budget->id)->update(['reserved_micros' => (int) $budget->reserved_micros - (int) $current->reserved_micros, 'unresolved_count' => (int) $budget->unresolved_count - 1, 'updated_at' => now()]);
            DB::table('ai_calls')->where('id', $call->id)->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
        });
    }

    private function uncertain(object $call, string $model, string $code): AiResult
    {
        DB::table('ai_calls')->where('id', $call->id)->where('status', 'reserved')->update(['status' => 'uncertain', 'error_code' => $code, 'updated_at' => now()]);

        return new AiResult(null, [], ['promptTokens' => null, 'completionTokens' => null], $model, 0, null, $call->id, ['code' => $code, 'retryable' => false]);
    }

    private function replay(object $call, string $model): AiResult
    {
        if ($call->status === 'completed' && is_string($call->result_encrypted)) {
            $result = AiResult::fromArray(json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR));
            if (! hash_equals((string) $call->id, $result->aiCallId)) {
                throw new LogicException('AI stored result does not match call.');
            }

            return $result;
        }
        if ($call->status === 'failed') {
            throw new Rejected($call->error_code === 'reconciled_billed_without_result'
                ? 'Previous AI attempt was billed without a usable result; an explicit new attempt is required.'
                : 'Previous AI attempt was confirmed unbilled; an explicit new attempt is required.');
        }
        $code = $call->status === 'reserved' ? 'in_progress' : ($call->error_code ?? 'provider_uncertain');

        return new AiResult(null, [], ['promptTokens' => null, 'completionTokens' => null], $model, 0, null, $call->id, ['code' => $code, 'retryable' => false]);
    }

    private function attribute(object $call, #[\SensitiveParameter] AiResult $result): void
    {
        $key = ['organization_id' => $call->organization_id, 'brand_id' => $call->brand_id, 'object_type' => $call->object_type, 'object_id' => $call->object_id];
        DB::table('object_costs')->insertOrIgnore([...$key, 'id' => (string) Str::ulid(), 'cost_micros' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('object_costs')->where($key)->incrementEach(['cost_micros' => $result->costMicros, 'prompt_tokens' => $result->usage['promptTokens'], 'completion_tokens' => $result->usage['completionTokens']], ['updated_at' => now()]);
    }

    private function assertThresholdReady(): void
    {
        $threshold = config('features.E0-05.defaults.budget_threshold_basis_points');
        if ($threshold === null) {
            return;
        }
        if (! is_int($threshold) || $threshold < 1 || $threshold > 10000) {
            throw new InvalidArgumentException('Invalid AI budget threshold.');
        }
        $contract = NotificationDispatcher::class;
        if (! interface_exists($contract) || ! class_exists(DomainEvent::class) || ! app()->bound($contract)) {
            throw new LogicException('AI budget notification requires the E0-04 transactional outbox.');
        }
    }

    private function notifyThreshold(object $budget, int $spent): void
    {
        $threshold = config('features.E0-05.defaults.budget_threshold_basis_points');
        if ($threshold === null || $budget->limit_micros === null || (int) $budget->limit_micros <= 0 || $budget->threshold_notified_at !== null) {
            return;
        }
        $reached = DB::selectOne('SELECT CAST(? AS numeric) * 10000 >= CAST(? AS numeric) * CAST(? AS numeric) AS reached', [$spent, $budget->limit_micros, $threshold])->reached;
        if (! $reached) {
            return;
        }
        $fraction = intdiv($threshold, 10000).'.'.str_pad((string) ($threshold % 10000), 4, '0', STR_PAD_LEFT);
        $event = new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'ai.budget.threshold_reached',
            'occurred_at' => now()->toIso8601String(), 'organization_id' => $budget->organization_id, 'brand_id' => $budget->brand_id,
            'actor_id' => app(TenantContext::class)->actorId, 'aggregate' => ['type' => 'ai_budget', 'id' => $budget->id, 'version' => 1],
            'data' => ['budget_id' => $budget->id, 'fraction' => $fraction],
        ]);
        app(NotificationDispatcher::class)->enqueue($event);
        DB::table('ai_budgets')->where('id', $budget->id)->update(['threshold_notified_at' => now()]);
    }

    private function assertResult(#[\SensitiveParameter] array $data): void
    {
        if (! array_key_exists('content', $data) || (! is_string($data['content']) && $data['content'] !== null) || ! is_array($data['toolCalls'] ?? null) || ! array_is_list($data['toolCalls']) || ! is_string($data['model'] ?? null) || ! is_int($data['latencyMs'] ?? null) || $data['latencyMs'] < 0 || ! array_key_exists('costMicros', $data) || ($data['costMicros'] !== null && (! is_int($data['costMicros']) || $data['costMicros'] < 0))) {
            throw new LogicException('Invalid AI driver result.');
        }
        foreach (['promptTokens', 'completionTokens'] as $key) {
            if (! is_int($data['usage'][$key] ?? null) || $data['usage'][$key] < 0) {
                throw new LogicException('Invalid AI driver usage.');
            }
        }
        foreach ($data['toolCalls'] as $tool) {
            if (! is_array($tool) || ! is_string($tool['id'] ?? null) || ! is_string($tool['name'] ?? null) || ! is_array($tool['arguments'] ?? null)) {
                throw new LogicException('Invalid AI tool call.');
            }
        }
    }

    private function assertTenantAndObject(#[\SensitiveParameter] AiContext $context): void
    {
        $tenant = app(TenantContext::class);
        if ($tenant->organizationId !== $context->organizationId || $tenant->brandId !== $context->brandId) {
            throw new LogicException('AI context does not match the active tenant.');
        }
        $table = match ($context->objectType) {
            'brand', 'onboarding' => 'brands', 'conversation' => 'conversations',
            'measurement' => 'measurements', 'audit_run' => 'audit_runs', 'release_eval' => 'knowledge_releases',
            'quiz' => 'visibility_quizzes', 'evaluation' => 'seller_evaluations',
            default => throw new InvalidArgumentException('Unsupported AI cost object.'),
        };
        if (! Schema::hasTable($table)) {
            throw new LogicException('AI cost object storage is unavailable.');
        }
        $query = DB::table($table)->where('id', $context->objectId)->where('organization_id', $context->organizationId);
        $query->where($table === 'brands' ? 'id' : 'brand_id', $context->brandId);
        if (! $query->exists() || ! DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('organizations')->whereColumn('organizations.id', 'brands.organization_id')->where('organizations.status', 'active'))->exists()) {
            throw new LogicException('AI cost object is outside the active tenant.');
        }
    }

    private function assertLiveAllowed(#[\SensitiveParameter] AiContext $context): void
    {
        if (! config('features.E0-05.defaults.live_enabled', false)) {
            throw new LogicException('Live AI calls are disabled.');
        }
        $settings = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;
        $grants = is_array($settings) ? ($settings['ai_data_policy'] ?? []) : [];
        foreach ($context->dataClasses as $class) {
            if (! in_array('any', is_array($grants[$class] ?? null) ? $grants[$class] : [], true)) {
                throw new LogicException('Brand data policy does not authorize this external AI transfer.');
            }
        }
    }

    private function driver(string $name): Driver
    {
        return match ($name) {
            'fake' => app(FakeDriver::class), 'record', 'openrouter' => app(OpenRouterDriver::class)
        };
    }

    private function record(#[\SensitiveParameter] array $body, #[\SensitiveParameter] array $result): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Recording is only allowed in local/testing.');
        }
        $directory = storage_path('app/private/ai-recordings');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LogicException('Cannot create private AI recording directory.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($body).'.json';
        $json = json_encode(['request' => $body, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new LogicException('Cannot write private AI recording.');
        }
        @chmod($path, 0600);
    }
}
