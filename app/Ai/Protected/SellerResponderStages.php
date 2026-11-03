<?php

namespace App\Ai\Protected;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\ProtectedOperations\FirstTurnGoal;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\ResponderRequest;
use App\Seller\Engine\ToolCatalog;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Server-owned first-turn responder input; no action or delivery is authorized. */
final class SellerResponderStages
{
    public function prepare(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $extractorId, #[SensitiveParameter] string $stateId): string
    {
        try {
            return DB::transaction(function () use ($http, $claim, $extractorId, $stateId): string {
                [$request, , $release] = $this->build($http, $claim, $extractorId, $stateId);
                $existing = DB::table('c02_responder_stages')->where('turn_id', $claim->turnId)
                    ->whereNotIn('id', DB::table('e309_responder_continuations')->select('stage_id'))->lockForUpdate()->first();
                if ($existing) {
                    $this->verify($existing, $claim, $extractorId, $stateId, $release, $request);

                    return $existing->id;
                }
                $json = $this->requestJson($request);
                $data = ['id' => (string) Str::ulid(), 'organization_id' => $claim->organizationId, 'brand_id' => $claim->brandId,
                    'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'extractor_stage_id' => $extractorId,
                    'first_state_id' => $stateId, 'release_id' => $release, 'request_encrypted' => Crypt::encryptString($json),
                    'request_digest' => $this->digest($json)];
                $data['seal'] = $this->seal((object) $data);
                DB::table('c02_responder_stages')->insert($data);

                return $data['id'];
            });
        } catch (Throwable) {
            throw new LogicException('Protected responder stage rejected.');
        }
    }

    public function prepareContinuation(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim,
        #[SensitiveParameter] string $previousId, #[SensitiveParameter] array $toolResults = [],
        #[SensitiveParameter] array $guardIssues = []): string
    {
        try {
            return DB::transaction(function () use ($http, $claim, $previousId, $toolResults, $guardIssues): string {
                $previous = DB::table('c02_responder_stages')->where('id', $previousId)->where('turn_id', $claim->turnId)
                    ->lockForUpdate()->first() ?? throw new LogicException;
                $existing = DB::table('e309_responder_continuations')->where('previous_stage_id', $previousId)->first();
                if ($existing) {
                    $this->load($http, $claim, $existing->stage_id);

                    return $existing->stage_id;
                }
                $payload = ['tool_results' => $toolResults, 'guard_issues' => $guardIssues];
                [$request, , $release] = $this->build($http, $claim, $previous->extractor_stage_id,
                    $previous->first_state_id, $toolResults, $guardIssues);
                $json = $this->requestJson($request);
                $data = ['id' => (string) Str::ulid(), 'organization_id' => $claim->organizationId,
                    'brand_id' => $claim->brandId, 'conversation_id' => $claim->conversationId,
                    'turn_id' => $claim->turnId, 'extractor_stage_id' => $previous->extractor_stage_id,
                    'first_state_id' => $previous->first_state_id, 'release_id' => $release,
                    'request_encrypted' => Crypt::encryptString($json), 'request_digest' => $this->digest($json)];
                $data['seal'] = $this->seal((object) $data);
                DB::table('c02_responder_stages')->insert($data);
                DB::table('e309_responder_continuations')->insert(['stage_id' => $data['id'],
                    'previous_stage_id' => $previousId,
                    'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);

                return $data['id'];
            });
        } catch (Throwable) {
            throw new LogicException('Protected responder continuation rejected.');
        }
    }

    /** Stage ID is only a lookup: all source authorization is repeated. */
    public function load(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId): array
    {
        try {
            return DB::transaction(function () use ($http, $claim, $stageId): array {
                $candidate = DB::table('c02_responder_stages')->where('id', $stageId)->first();
                if (! $candidate) {
                    throw new LogicException;
                }
                // Source locks precede the responder row lock in both paths.
                $continuation = DB::table('e309_responder_continuations')->where('stage_id', $stageId)->first();
                $payload = $continuation ? json_decode(Crypt::decryptString($continuation->payload_encrypted), true, 512, JSON_THROW_ON_ERROR) : [];
                if (! is_array($payload) || ! is_array($payload['tool_results'] ?? []) || ! is_array($payload['guard_issues'] ?? [])) {
                    throw new LogicException;
                }
                [$request, $context, $release] = $this->build($http, $claim, $candidate->extractor_stage_id,
                    $candidate->first_state_id, $payload['tool_results'] ?? [], $payload['guard_issues'] ?? []);
                $row = DB::table('c02_responder_stages')->where('id', $stageId)->lockForUpdate()->first();
                if (! $row) {
                    throw new LogicException;
                }
                $this->verify($row, $claim, $candidate->extractor_stage_id, $candidate->first_state_id, $release, $request);
                $context = new AiContext($context->organizationId, $context->brandId, 'responder', 'seller', 'conversation',
                    $claim->conversationId, 'c02-responder-'.$row->id, ['conversation_content', 'client_profile', 'knowledge_internal']);

                return [$request, $context, $row->request_digest, $row->ai_call_id, $row->gateway_request_hash];
            });
        } catch (Throwable) {
            throw new LogicException('Protected responder stage rejected.');
        }
    }

    public function lockForGateway(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $digest): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected responder stage rejected.');
        }
        [, , $current] = $this->load($http, $claim, $stageId);
        if (! hash_equals($digest, $current)) {
            throw new LogicException('Protected responder stage rejected.');
        }
    }

    public function bindCall(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] object $call, #[SensitiveParameter] string $requestHash): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected responder stage rejected.');
        }
        [$request, $context] = $this->load($http, $claim, $stageId);
        $body = app(ContactMasker::class)->request($request->toArray());
        $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
        if (! hash_equals($expected, $requestHash) || ! hash_equals((string) $call->request_hash, $expected)
            || ! hash_equals((string) $call->idempotency_key_hash, hash_hmac('sha256', 'c02-responder-'.$stageId, (string) config('app.key')))) {
            throw new LogicException('Protected responder stage rejected.');
        }
        $row = DB::table('c02_responder_stages')->where('id', $stageId)->lockForUpdate()->first();
        if (! $row) {
            throw new LogicException('Protected responder stage rejected.');
        }
        if ($row->ai_call_id !== null) {
            if (! hash_equals($row->ai_call_id, $call->id) || ! hash_equals($row->gateway_request_hash, $requestHash)) {
                throw new LogicException('Protected responder stage rejected.');
            }

            return;
        }
        // Never let a cleared/old binding cause the server to sign a previous
        // call. This proof is checked here as well as by the database trigger.
        $proof = DB::selectOne("select pg_current_xact_id()::text as xid, current_setting('c02.reservation_nonce', true) as nonce");
        if (! is_string($proof->nonce) || preg_match('/\\A[a-f0-9]{64}\\z/D', $proof->nonce) !== 1
            || ! is_string($call->c02_creation_xid) || ! hash_equals($call->c02_creation_xid, $proof->xid)
            || ! is_string($call->c02_reservation_digest)
            || ! hash_equals($call->c02_reservation_digest, hash('sha256', 'c02-reservation:'.$proof->nonce))) {
            throw new LogicException('Protected responder stage rejected.');
        }
        $bound = DB::table('c02_responder_stages')->where('id', $stageId)->whereNull('ai_call_id')->update([
            'ai_call_id' => $call->id, 'gateway_request_hash' => $requestHash,
            'binding_seal' => $this->bindingSeal($row, $call->id, $requestHash),
        ]);
        if ($bound !== 1) {
            throw new LogicException('Protected responder stage rejected.');
        }
    }

    private function build(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim,
        #[SensitiveParameter] string $extractorId, #[SensitiveParameter] string $stateId,
        #[SensitiveParameter] array $toolResults = [], #[SensitiveParameter] array $guardIssues = []): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException;
        }
        $computed = app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $extractorId, $stateId);
        [, $context, , , , , $message] = app(SellerExtractorStages::class)->load($http, $claim, $extractorId);
        $stage = DB::table('c02_extractor_stages')->where('id', $extractorId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
        $tenant = app(TenantContext::class);
        $knowledge = app(KnowledgeRepository::class)->published($tenant);
        if (! $stage || ! is_string($message) || $stage->release_id !== $knowledge->releaseId()
            || ! hash_equals($stage->release_checksum, $knowledge->checksum()) || $computed['goal']->kind === 'silent') {
            throw new LogicException;
        }
        DB::table('prompt_sets')->where(function ($query) use ($tenant): void {
            $query->where(fn ($platform) => $platform->whereNull('organization_id')->whereNull('brand_id'))
                ->orWhere(fn ($brand) => $brand->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId));
        })->orderBy('id')->lockForUpdate()->get(['id']);
        $prompt = DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $prompt ??= DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->whereNull('organization_id')->whereNull('brand_id')->first();
        $trusted = file_get_contents(resource_path('views/prompts/responder.blade.php'));
        $name = $knowledge->persona()['name'] ?? null;
        $model = config('features.E0-05.defaults.seller_defaults.responder.model');
        $temperature = config('features.E0-05.defaults.seller_defaults.responder.temperature');
        if (! $prompt || ! is_string($trusted) || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum)
            || ! hash_equals(hash('sha256', $trusted), $prompt->checksum) || ! is_string($name) || trim($name) === ''
            || ! is_string($model) || $model === '' || ! is_numeric($temperature)) {
            throw new LogicException;
        }
        $goal = $computed['goal'];
        $definitions = ToolCatalog::definitionsFor($goal->allowedTools);
        if (array_column(array_column($definitions, 'function'), 'name') !== $goal->allowedTools) {
            throw new LogicException;
        }
        $state = new LeadState($computed['state']);
        $system = app(ResponderProjection::class)->render($knowledge, $state, $goal, $name);
        if ($goal->kind === 'intro_and_ask') {
            $channelId = DB::table('conversations')->where('id', $claim->conversationId)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('channel_account_id');
            $channel = $channelId ? DB::table('channel_accounts')->where('id', $channelId)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('type') : null;
            if ($channel === 'widget') {
                $settings = DB::table('brand_seller_settings')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->value('greetings');
                $greeting = is_string($settings) ? (json_decode($settings, true)['widget'] ?? null) : null;
                if (is_string($greeting) && trim($greeting) !== '') {
                    $system .= "\n\nНастроенное приветствие для сайта: ".trim($greeting);
                }
            }
        }
        $request = app(ResponderRequest::class)->build($system, [['role' => 'client', 'text' => $message]],
            $toolResults === [] ? $goal->allowedTools : [], $model, (float) $temperature,
            $prompt->version, $prompt->checksum, $toolResults, $guardIssues);

        return [$request, $context, $knowledge->releaseId()];
    }

    private function verify(#[SensitiveParameter] object $row, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $extractorId, #[SensitiveParameter] string $stateId, #[SensitiveParameter] string $releaseId, #[SensitiveParameter] AiRequest $request): void
    {
        if ($row->organization_id !== $claim->organizationId || $row->brand_id !== $claim->brandId
            || $row->conversation_id !== $claim->conversationId || $row->turn_id !== $claim->turnId
            || $row->extractor_stage_id !== $extractorId || $row->first_state_id !== $stateId || $row->release_id !== $releaseId
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
        if (! hash_equals($row->request_digest, $this->digest($saved)) || ! hash_equals($row->request_digest, $this->digest($this->requestJson($request)))) {
            throw new LogicException;
        }
    }

    private function requestJson(#[SensitiveParameter] AiRequest $request): string
    {
        return json_encode($request->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function digest(#[SensitiveParameter] string $json): string
    {
        return hash_hmac('sha256', 'c02-responder-request:'.$json, (string) config('app.key'));
    }

    private function seal(#[SensitiveParameter] object $row): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'extractor_stage_id', 'first_state_id', 'release_id', 'request_digest'] as $field) {
            $identity[$field] = (string) $row->{$field};
        }

        return hash_hmac('sha256', 'c02-responder-stage:'.CanonicalJson::hash($identity), (string) config('app.key'));
    }

    private function bindingSeal(#[SensitiveParameter] object $row, string $callId, string $requestHash): string
    {
        return hash_hmac('sha256', 'c02-responder-binding:'.CanonicalJson::hash([
            'stage_id' => $row->id, 'stage_seal' => $row->seal, 'call_id' => $callId, 'request_hash' => $requestHash,
        ]), (string) config('app.key'));
    }
}
