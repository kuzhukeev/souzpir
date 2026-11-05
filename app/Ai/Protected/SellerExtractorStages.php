<?php

namespace App\Ai\Protected;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\ExtractorEffectReservations;
use App\ProtectedOperations\FirstTurnProtectedClaim;
use App\ProtectedOperations\JobFirstTurnClaim;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\BrandPromptSets;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Server-owned extractor inputs and call binding; never authorizes a Seller effect. */
final class SellerExtractorStages
{
    /** Build extractor input from the current job claim and persisted first message. */
    public function prepareJobInput(#[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] TurnClaim $claim): array
    {
        try {
            return app(TenantContextRunner::class)->run($tenant, fn () => DB::transaction(function () use ($tenant, $claim): array {
                app(JobFirstTurnClaim::class)->assertCurrent($tenant, $claim);
                [$request, $state, $message, $engine] = $this->inputs($tenant, $claim);
                $context = new AiContext($tenant->organizationId, $tenant->brandId, 'extractor', 'seller', 'conversation',
                    $claim->conversationId, 'job-extractor-'.$claim->turnId, ['conversation_content', 'client_profile', 'knowledge_internal']);

                return [$request, $context, $this->digest($this->inputJson($request, $state, $message)), $state, $message, $engine];
            }));
        } catch (Throwable) {
            throw new LogicException('Protected extractor stage rejected.');
        }
    }

    /** Persist the prepared job input in the encrypted, tenant-scoped receipt. */
    public function prepareJobStoredInput(#[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] TurnClaim $claim): array
    {
        try {
            return app(TenantContextRunner::class)->run($tenant, fn () => DB::transaction(function () use ($tenant, $claim): array {
                app(JobFirstTurnClaim::class)->assertCurrent($tenant, $claim);
                $receipt = app(PostgresTransactionalIdempotency::class)->execute(
                    $tenant->requireOrganizationId(), $tenant->brandId, 'seller.job_extractor_input', $claim->turnId,
                    json_encode(['turn_id' => $claim->turnId, 'conversation_id' => $claim->conversationId], JSON_THROW_ON_ERROR),
                    function () use ($tenant, $claim): array {
                        [$request, , $digest, $state, $message] = $this->prepareJobInput($tenant, $claim);

                        return ['input' => $this->inputJson($request, $state, $message), 'digest' => $digest];
                    },
                );
                if (! is_string($receipt['input'] ?? null) || ! is_string($receipt['digest'] ?? null)
                    || ! hash_equals($receipt['digest'], $this->digest($receipt['input']))) {
                    throw new LogicException;
                }

                return $receipt;
            }));
        } catch (Throwable) {
            throw new LogicException('Protected extractor stage rejected.');
        }
    }

    public function prepare(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim): string
    {
        try {
            return $this->authorized($http, $claim, function (TenantContext $tenant, User $user, object $selection) use ($http, $claim): string {
                $existing = DB::table('c02_extractor_stages')->where('turn_id', $claim->turnId)->lockForUpdate()->first();
                if ($existing) {
                    $this->verify($existing, $http, $claim, $tenant, $user, $selection);
                    app(ExtractorEffectReservations::class)->assertBoundIfPresentLocked($existing);

                    return $existing->id;
                }
                [$request, $state, $message, $engine, $release] = $this->inputs($tenant, $claim);
                $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
                $json = $this->inputJson($request, $state, $message);
                $id = (string) Str::ulid();
                $data = ['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'actor_id' => $user->id,
                    'session_hash' => $this->sessionHash($http), 'selection_revision' => (int) $selection->brand_revision,
                    'history_revision' => (int) $turn->base_history_revision, 'cutoff_sequence' => (int) $turn->cutoff_sequence,
                    'base_version' => $claim->baseVersion, 'release_id' => $release->releaseId(), 'release_checksum' => $release->checksum(),
                    'input_encrypted' => Crypt::encryptString($json), 'input_digest' => $this->digest($json)];
                $data['seal'] = $this->seal((object) $data);
                DB::table('c02_extractor_stages')->insert($data);

                return $id;
            });
        } catch (Throwable) {
            throw new LogicException('Protected extractor stage rejected.');
        }
    }

    /** The stage ID identifies a row; the session, rights and claim are checked on every use. */
    public function load(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId): array
    {
        try {
            return $this->authorized($http, $claim, function (TenantContext $tenant, User $user, object $selection) use ($http, $claim, $stageId): array {
                $row = DB::table('c02_extractor_stages')->where('id', $stageId)->lockForUpdate()->first();
                if (! $row) {
                    throw new LogicException;
                }
                [$request, $state, $message, $engine] = $this->verify($row, $http, $claim, $tenant, $user, $selection);
                app(ExtractorEffectReservations::class)->assertBoundIfPresentLocked($row);
                $context = new AiContext($tenant->organizationId, $tenant->brandId, 'extractor', 'seller', 'conversation',
                    $claim->conversationId, 'c02-extractor-'.$row->id, ['conversation_content', 'client_profile', 'knowledge_internal']);

                return [$request, $context, $row->input_digest, $row->ai_call_id, $row->gateway_request_hash, $state, $message, $engine];
            });
        } catch (Throwable) {
            throw new LogicException('Protected extractor stage rejected.');
        }
    }

    public function lockForGateway(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $digest): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected extractor stage rejected.');
        }
        [, , $current] = $this->load($http, $claim, $stageId);
        if (! hash_equals($digest, $current)) {
            throw new LogicException('Protected extractor stage rejected.');
        }
    }

    public function bindCall(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] object $call, #[SensitiveParameter] string $requestHash): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected extractor stage rejected.');
        }
        [$request, $context] = $this->load($http, $claim, $stageId);
        $body = app(ContactMasker::class)->request($request->toArray());
        $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
        if (! hash_equals($expected, $requestHash) || ! hash_equals((string) $call->request_hash, $expected)) {
            throw new LogicException('Protected extractor stage rejected.');
        }
        $stage = DB::table('c02_extractor_stages')->where('id', $stageId)->lockForUpdate()->first();
        if (! $stage || ! hash_equals((string) $call->idempotency_key_hash, hash_hmac('sha256', 'c02-extractor-'.$stageId, (string) config('app.key')))) {
            throw new LogicException('Protected extractor stage rejected.');
        }
        if ($stage->ai_call_id !== null) {
            if (! hash_equals($stage->ai_call_id, $call->id) || ! hash_equals($stage->gateway_request_hash, $requestHash)) {
                throw new LogicException('Protected extractor stage rejected.');
            }

            return;
        }
        $bound = DB::table('c02_extractor_stages')->where('id', $stageId)->whereNull('ai_call_id')->update(['ai_call_id' => $call->id, 'gateway_request_hash' => $requestHash]);
        if ($bound !== 1) {
            throw new LogicException('Protected extractor stage rejected.');
        }
        app(ExtractorEffectReservations::class)->recordNewLocked($http, $claim, $stageId, $call->id, $requestHash);
    }

    private function authorized(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] \Closure $action): mixed
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Protected extractor stage rejected.');
        }

        return app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $claim, $action): mixed {
            $tenant = app(TenantContext::class);
            $selection = app(SessionBrandSelection::class)->current($http);
            if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail() || ! $tenant->membershipId
                || $selection->selected_organization_id !== $tenant->organizationId || $selection->selected_brand_id !== $tenant->brandId
                || $claim->organizationId !== $tenant->organizationId || $claim->brandId !== $tenant->brandId) {
                throw new LogicException;
            }
            // An ordinary public TurnClaim is not a protected first-turn origin.
            // Recheck it for preparation, reservation, binding and result reads.
            app(FirstTurnProtectedClaim::class)->assertProtectedLocked($http, $claim);
            app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
            $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->lockForUpdate()->first();
            // The turn fence holds the organization row. Lock existing plan
            // assignments, plans and their limits before evaluating access;
            // their FKs serialize new rows with those parent locks as well.
            $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)
                ->orderBy('id')->lockForUpdate()->get(['plan_id']);
            $planIds = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
            if ($planIds !== []) {
                DB::table('plans')->whereIn('id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
                DB::table('plan_limits')->whereIn('plan_id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            if (! $member || ! $member->permitsBrand($tenant->brandId) || ! $module || ! $module->enabled
                || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                throw new LogicException;
            }

            return $action($tenant, $user, $selection);
        });
    }

    private function inputs(#[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] TurnClaim $claim): array
    {
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $input = DB::table('messages')->where('id', $turn->input_message_id)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('role', 'client')
            ->where('history_sequence', $turn->cutoff_sequence)->first();
        if (! $conversation || ! $input || ! is_string($input->text)) {
            throw new LogicException;
        }
        $stateData = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($stateData)) {
            throw new LogicException;
        }
        $state = new LeadState($stateData);
        $history = DB::table('messages')->where('conversation_id', $claim->conversationId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->whereIn('role', ['client', 'agent'])->where('history_sequence', '<', $turn->cutoff_sequence)
            ->orderByDesc('history_sequence')->limit(6)->get(['role', 'text'])->reverse()->values()->map(fn ($row) => ['role' => $row->role, 'text' => $row->text])->all();
        $release = app(KnowledgeRepository::class)->published($tenant);
        $persona = $release->persona();
        $name = $persona['name'] ?? null;
        // Serialize prompt selection and publication changes with this stage.
        // The already locked brand row also fences new brand-owned prompt rows.
        DB::table('prompt_sets')->where(function ($query) use ($tenant): void {
            $query->where(fn ($platform) => $platform->whereNull('organization_id')->whereNull('brand_id'))
                ->orWhere(fn ($brand) => $brand->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId));
        })->orderBy('id')->lockForUpdate()->get(['id']);
        $promptRow = app(BrandPromptSets::class)->load($tenant, $release, 'extractor');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, $promptRow->version);
        $model = config('features.E0-05.defaults.seller_defaults.extractor.model');
        $temperature = config('features.E0-05.defaults.seller_defaults.extractor.temperature');
        if (! is_string($name) || trim($name) === '' || ! is_string($model) || $model === '' || ! is_numeric($temperature)) {
            throw new LogicException;
        }
        $engine = new Extractor(app(AiGateway::class), $release, $prompt, $model, (float) $temperature, $name);

        return [$engine->buildRequest($state, $history, $input->text), $state, $input->text, $engine, $release];
    }

    private function verify(#[SensitiveParameter] object $row, #[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] User $user, #[SensitiveParameter] object $selection): array
    {
        if ($row->organization_id !== $tenant->organizationId || $row->brand_id !== $tenant->brandId
            || $row->conversation_id !== $claim->conversationId || $row->turn_id !== $claim->turnId || $row->actor_id !== $user->id
            || ! hash_equals($row->session_hash, $this->sessionHash($http)) || (string) $row->selection_revision !== (string) $selection->brand_revision
            || (int) $row->base_version !== $claim->baseVersion || ! hash_equals($row->seal, $this->seal($row))) {
            throw new LogicException;
        }
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        if ((string) $row->history_revision !== (string) $turn->base_history_revision || (string) $row->cutoff_sequence !== (string) $turn->cutoff_sequence) {
            throw new LogicException;
        }
        [$request, $state, $message, $engine, $release] = $this->inputs($tenant, $claim);
        $saved = Crypt::decryptString($row->input_encrypted);
        if ($row->release_id !== $release->releaseId() || ! hash_equals($row->release_checksum, $release->checksum())
            || ! hash_equals($row->input_digest, $this->digest($saved))
            || ! hash_equals($row->input_digest, $this->digest($this->inputJson($request, $state, $message)))) {
            throw new LogicException;
        }

        return [$request, $state, $message, $engine];
    }

    private function inputJson(#[SensitiveParameter] AiRequest $request, #[SensitiveParameter] LeadState $state, #[SensitiveParameter] string $message): string
    {
        return json_encode(['request' => $request->toArray(), 'state' => $state->toArray(), 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function sessionHash(#[SensitiveParameter] Request $http): string
    {
        return hash_hmac('sha256', 'c02-extractor-session:'.$http->session()->getId(), (string) config('app.key'));
    }

    private function digest(#[SensitiveParameter] string $json): string
    {
        return hash_hmac('sha256', 'c02-extractor-input:'.$json, (string) config('app.key'));
    }

    private function seal(#[SensitiveParameter] object $row): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'actor_id', 'session_hash', 'selection_revision', 'history_revision', 'cutoff_sequence', 'base_version', 'release_id', 'release_checksum', 'input_digest'] as $field) {
            $identity[$field] = (string) $row->{$field};
        }

        return hash_hmac('sha256', 'c02-extractor-stage:'.CanonicalJson::hash($identity), (string) config('app.key'));
    }
}
