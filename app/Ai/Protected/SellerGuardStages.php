<?php

namespace App\Ai\Protected;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\ProtectedOperations\FirstTurnDeterministicGuard;
use App\ProtectedOperations\FirstTurnGoal;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\BrandPromptSets;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\GuardRequest;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** First-turn model guard input; not permission to send or execute an effect. */
final class SellerGuardStages
{
    public function prepare(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId): string
    {
        try {
            return DB::transaction(function () use ($http, $claim, $responderId): string {
                [$request, , $release, $deterministic] = $this->build($http, $claim, $responderId);
                $existing = DB::table('c02_guard_stages')->where('turn_id', $claim->turnId)->lockForUpdate()->first();
                if ($existing) {
                    $this->verify($existing, $claim, $responderId, $release, $request, $deterministic);

                    return $existing->id;
                }
                $json = $this->requestJson($request, $deterministic);
                $data = ['id' => (string) Str::ulid(), 'organization_id' => $claim->organizationId, 'brand_id' => $claim->brandId,
                    'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'responder_stage_id' => $responderId,
                    'release_id' => $release, 'request_encrypted' => Crypt::encryptString($json), 'request_digest' => $this->digest($json)];
                $data['seal'] = $this->seal((object) $data);
                DB::table('c02_guard_stages')->insert($data);

                return $data['id'];
            });
        } catch (Throwable) {
            throw new LogicException('Protected model guard stage rejected.');
        }
    }

    /** ID is only a lookup; sources and authority are rechecked under locks. */
    public function load(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId): array
    {
        try {
            return DB::transaction(function () use ($http, $claim, $stageId): array {
                $candidate = DB::table('c02_guard_stages')->where('id', $stageId)->first();
                if (! $candidate) {
                    throw new LogicException;
                }
                [$request, $context, $release, $deterministic, $goal] = $this->build($http, $claim, $candidate->responder_stage_id);
                $row = DB::table('c02_guard_stages')->where('id', $stageId)->lockForUpdate()->first();
                if (! $row) {
                    throw new LogicException;
                }
                $this->verify($row, $claim, $candidate->responder_stage_id, $release, $request, $deterministic);
                $context = new AiContext($context->organizationId, $context->brandId, 'guard', 'seller', 'conversation',
                    $claim->conversationId, 'c02-guard-'.$row->id, ['conversation_content', 'client_profile', 'knowledge_internal']);

                return [$request, $context, $row->request_digest, $row->ai_call_id, $row->gateway_request_hash, $deterministic, $goal];
            });
        } catch (Throwable) {
            throw new LogicException('Protected model guard stage rejected.');
        }
    }

    public function lockForGateway(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $digest): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected model guard stage rejected.');
        }
        [, , $current] = $this->load($http, $claim, $stageId);
        if (! hash_equals($digest, $current)) {
            throw new LogicException('Protected model guard stage rejected.');
        }
    }

    public function bindCall(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] object $call, #[SensitiveParameter] string $requestHash): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected model guard stage rejected.');
        }
        [$request, $context] = $this->load($http, $claim, $stageId);
        $body = app(ContactMasker::class)->request($request->toArray());
        $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
        if (! hash_equals($expected, $requestHash) || ! hash_equals((string) $call->request_hash, $expected)
            || ! hash_equals((string) $call->idempotency_key_hash, hash_hmac('sha256', 'c02-guard-'.$stageId, (string) config('app.key')))) {
            throw new LogicException('Protected model guard stage rejected.');
        }
        $row = DB::table('c02_guard_stages')->where('id', $stageId)->lockForUpdate()->first();
        if (! $row) {
            throw new LogicException('Protected model guard stage rejected.');
        }
        if ($row->ai_call_id !== null) {
            if (! hash_equals($row->ai_call_id, $call->id) || ! hash_equals($row->gateway_request_hash, $requestHash)) {
                throw new LogicException('Protected model guard stage rejected.');
            }

            return;
        }
        // A cleared binding must not allow an older call to be signed as new,
        // even if the database stage trigger is disabled.
        $proof = DB::selectOne("select pg_current_xact_id()::text as xid, current_setting('c02.reservation_nonce', true) as nonce");
        if (! is_string($proof->nonce) || preg_match('/\\A[a-f0-9]{64}\\z/D', $proof->nonce) !== 1
            || ! is_string($call->c02_creation_xid) || ! hash_equals($call->c02_creation_xid, $proof->xid)
            || ! is_string($call->c02_reservation_digest)
            || ! hash_equals($call->c02_reservation_digest, hash('sha256', 'c02-reservation:'.$proof->nonce))) {
            throw new LogicException('Protected model guard stage rejected.');
        }
        $bound = DB::table('c02_guard_stages')->where('id', $stageId)->whereNull('ai_call_id')->update([
            'ai_call_id' => $call->id, 'gateway_request_hash' => $requestHash,
            'binding_seal' => $this->bindingSeal($row, $call->id, $requestHash),
        ]);
        if ($bound !== 1) {
            throw new LogicException('Protected model guard stage rejected.');
        }
    }

    private function build(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId): array
    {
        if (DB::connection()->transactionLevel() < 1 || config('agent.guard_llm', false) !== true) {
            throw new LogicException;
        }
        $deterministic = app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $responderId);
        $responder = DB::table('c02_responder_stages')->where('id', $responderId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
        if (! $responder) {
            throw new LogicException;
        }
        $computed = app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $responder->extractor_stage_id, $responder->first_state_id);
        $goal = $computed['goal'];
        if (! app(GuardDecision::class)->requiresModel($deterministic, $goal, true)) {
            throw new LogicException;
        }
        $candidate = app(Gateway::class)->readProtectedResponderLocked($http, $claim, $responderId);
        $tenant = app(TenantContext::class);
        $knowledge = app(KnowledgeRepository::class)->published($tenant);
        if (($candidate['status'] ?? null) !== 'candidate' || ! is_string($candidate['text'] ?? null)
            || $responder->release_id !== $knowledge->releaseId()) {
            throw new LogicException;
        }
        DB::table('prompt_sets')->where(function ($query) use ($tenant): void {
            $query->where(fn ($platform) => $platform->whereNull('organization_id')->whereNull('brand_id'))
                ->orWhere(fn ($brand) => $brand->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId));
        })->orderBy('id')->lockForUpdate()->get(['id']);
        $prompt = app(BrandPromptSets::class)->load($tenant, $knowledge, 'guard');
        $model = config('features.E0-05.defaults.seller_defaults.guard.model');
        if (! $prompt || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum) || ! is_string($model) || $model === '') {
            throw new LogicException;
        }
        $state = new LeadState($computed['state']);
        $projection = app(GuardProjection::class)->render($state, $goal, $prompt->body);
        if (! hash_equals($projection['checksum'], $prompt->checksum)) {
            throw new LogicException;
        }
        $request = app(GuardRequest::class)->build($projection['body'], $candidate['text'], $model, $prompt->version, $prompt->checksum);

        return [$request, $tenant, $knowledge->releaseId(), $deterministic, $goal];
    }

    private function verify(#[SensitiveParameter] object $row, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId, #[SensitiveParameter] string $releaseId, #[SensitiveParameter] AiRequest $request, #[SensitiveParameter] array $deterministic): void
    {
        if ($row->organization_id !== $claim->organizationId || $row->brand_id !== $claim->brandId
            || $row->conversation_id !== $claim->conversationId || $row->turn_id !== $claim->turnId
            || $row->responder_stage_id !== $responderId || $row->release_id !== $releaseId
            || ! hash_equals($row->seal, $this->seal($row))) {
            throw new LogicException;
        }
        if ($row->ai_call_id === null) {
            if ($row->gateway_request_hash !== null || $row->binding_seal !== null) {
                throw new LogicException;
            }
        } elseif (! is_string($row->gateway_request_hash) || ! is_string($row->binding_seal)
            || ! hash_equals($row->binding_seal, $this->bindingSeal($row, $row->ai_call_id, $row->gateway_request_hash))) {
            // Pre-migration bindings have no authenticated call identity.
            throw new LogicException;
        }
        $saved = Crypt::decryptString($row->request_encrypted);
        if (! hash_equals($row->request_digest, $this->digest($saved)) || ! hash_equals($row->request_digest, $this->digest($this->requestJson($request, $deterministic)))) {
            throw new LogicException;
        }
    }

    private function requestJson(#[SensitiveParameter] AiRequest $request, #[SensitiveParameter] array $deterministic): string
    {
        return json_encode(['request' => $request->toArray(), 'deterministic' => $deterministic], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function digest(#[SensitiveParameter] string $json): string
    {
        return hash_hmac('sha256', 'c02-model-guard-request:'.$json, (string) config('app.key'));
    }

    private function seal(#[SensitiveParameter] object $row): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'responder_stage_id', 'release_id', 'request_digest'] as $field) {
            $identity[$field] = (string) $row->{$field};
        }

        return hash_hmac('sha256', 'c02-model-guard-stage:'.CanonicalJson::hash($identity), (string) config('app.key'));
    }

    private function bindingSeal(#[SensitiveParameter] object $row, string $callId, string $requestHash): string
    {
        return hash_hmac('sha256', 'c02-model-guard-binding:'.CanonicalJson::hash([
            'stage_id' => $row->id, 'stage_seal' => $row->seal, 'call_id' => $callId, 'request_hash' => $requestHash,
        ]), (string) config('app.key'));
    }
}
