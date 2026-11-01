<?php

namespace App\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Ai\Gateway;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Narrow first-inbound text storage; no delivery or tool effect is performed. */
final class FirstTurnTextFinalizer
{
    public function finalize(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId, #[SensitiveParameter] ?string $modelGuardId, #[SensitiveParameter] string $idempotencyKey): array
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() !== 0
                || ! Str::isUlid($responderId) || ($modelGuardId !== null && ! Str::isUlid($modelGuardId))
                || $idempotencyKey === '' || strlen($idempotencyKey) > 512) {
                throw new LogicException;
            }

            return app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $claim, $responderId, $modelGuardId, $idempotencyKey): array {
                [$tenant, $selection] = $this->authorize($http, $user, $claim);
                $secret = config('app.key');
                if (! is_string($secret) || $secret === '') {
                    throw new LogicException;
                }
                $requestHash = CanonicalJson::hash(['turn' => $claim->turnId, 'conversation' => $claim->conversationId,
                    'base_version' => $claim->baseVersion, 'responder' => $responderId, 'model_guard' => $modelGuardId,
                    'actor' => $user->id, 'session' => hash_hmac('sha256', 'c02-finalizer-session:'.$http->session()->getId(), $secret),
                    'selection_revision' => (string) $selection->brand_revision, 'claim' => hash_hmac('sha256', 'c02-finalizer-claim:'.$claim->token(), $secret)]);

                return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.first_text_finalize',
                    $idempotencyKey, $requestHash, fn () => $this->write($http, $tenant, $claim, $responderId, $modelGuardId));
            });
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Idempotency key was used with different content.');
        } catch (Throwable) {
            throw new LogicException('Protected first-turn finalization unavailable.');
        }
    }

    private function authorize(#[SensitiveParameter] Request $http, User $user, #[SensitiveParameter] TurnClaim $claim): array
    {
        $tenant = app(TenantContext::class);
        $selection = app(SessionBrandSelection::class)->current($http);
        if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail() || ! $tenant->membershipId
            || $selection->selected_organization_id !== $tenant->organizationId || $selection->selected_brand_id !== $tenant->brandId
            || $claim->status !== 'claimed' || $claim->organizationId !== $tenant->organizationId || $claim->brandId !== $tenant->brandId
            || $claim->baseVersion !== 0 || ! Str::isUlid($claim->turnId) || ! Str::isUlid($claim->conversationId)
            || ! is_string($claim->token()) || preg_match('/\A[a-f0-9]{64}\z/D', $claim->token()) !== 1) {
            throw new LogicException;
        }
        ActiveTenantLock::acquire($tenant);
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        if (! $conversation || ! $turn || ! is_string($turn->claim_hash) || ! hash_equals($turn->claim_hash, hash('sha256', $claim->token()))
            || (int) $turn->base_version !== $claim->baseVersion) {
            throw new LogicException;
        }
        $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
            ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
        $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('module', 'seller')->lockForUpdate()->first();
        $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)->orderBy('id')->lockForUpdate()->get(['plan_id']);
        $planIds = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
        if ($planIds !== []) {
            DB::table('plans')->whereIn('id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('plan_limits')->whereIn('plan_id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        if (! $member || ! $member->permitsBrand($tenant->brandId) || ! $module || ! $module->enabled
            || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
            throw new LogicException;
        }

        return [$tenant, $selection];
    }

    private function write(#[SensitiveParameter] Request $http, #[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId, #[SensitiveParameter] ?string $modelGuardId): array
    {
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $candidate = app(Gateway::class)->readProtectedResponderLocked($http, $claim, $responderId);
        if (($candidate['status'] ?? null) !== 'candidate' || ! is_string($candidate['text'] ?? null)) {
            throw new LogicException;
        }
        $responder = DB::table('c02_responder_stages')->where('id', $responderId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
        if (! $responder) {
            throw new LogicException;
        }
        $computed = app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $responder->extractor_stage_id, $responder->first_state_id);
        $det = app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $responderId);
        $goal = $computed['goal'];
        $state = new LeadState($computed['state']);
        $enabled = config('agent.guard_llm', false);
        if (! is_bool($enabled)) {
            throw new LogicException;
        }
        $decisionService = app(GuardDecision::class);
        if ($decisionService->requiresModel($det, $goal, $enabled)) {
            if ($modelGuardId === null || ! DB::table('c02_guard_stages')->where('id', $modelGuardId)->where('turn_id', $claim->turnId)
                ->where('responder_stage_id', $responderId)->exists()) {
                throw new LogicException;
            }
            $guard = app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $modelGuardId);
        } else {
            if ($modelGuardId !== null) {
                throw new LogicException;
            }
            $guard = $decisionService->finish($det, $goal, $enabled);
        }
        if (($guard['status'] ?? null) !== 'completed' || ($guard['verdict'] ?? null) !== 'ok'
            || ! in_array($goal->kind, ['ask_slot', 'answer_and_ask', 'small_talk', 'unclear', 'closed_topic', 'decline_off_topic', 'decline_injection', 'bot_disclosure'], true)
            || ($state->signal('notify_manager') && ! $state->flag('manager_notified'))) {
            throw new LogicException;
        }
        $extraction = app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $responder->extractor_stage_id);
        if (($extraction['client_intent'] ?? null) === 'ask_requisites') {
            throw new LogicException;
        }
        $knowledge = app(KnowledgeRepository::class)->published($tenant);
        if ($responder->release_id !== $knowledge->releaseId()) {
            throw new LogicException;
        }
        if ($goal->slot) {
            $state->markAsked($goal->slot);
            if ($goal->slot === 'budget_range') {
                $state->inc('budget_asks');
            }
        }
        if ($goal->trigger) {
            $state->useTrigger($goal->trigger['id']);
            $state->setSignal('last_trigger_turn', $state->counter('turns'));
        }
        $policy = new Policy($knowledge);
        $state->setOutcome($policy->computeOutcome($state));
        $state->data['missing'] = $policy->computeMissing($state);
        $messageId = (string) Str::ulid();
        $message = ['id' => $messageId, 'role' => 'agent', 'text' => $candidate['text'], 'meta' => []];
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, 'completed', $claim->baseVersion + 1, $messageId,
            $goal->toArray(), $guard, [], $state->toArray(), [$message]);
        DB::table('messages')->insert(['id' => $messageId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $claim->conversationId, 'role' => 'agent', 'text' => $candidate['text'], 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->update([
            'state' => json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'stage' => $state->stage(), 'outcome' => $state->outcome(),
            'client_type' => $state->clientType(), 'version' => $claim->baseVersion + 1, 'updated_at' => now(),
        ]);
        $encoded = json_encode($outcome->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        DB::table('seller_turns')->where('id', $claim->turnId)->where('status', 'processing')->update([
            'status' => 'completed', 'outcome' => $encoded, 'outcome_hash' => \App\Seller\Conversations\CanonicalJson::hash($outcome->toArray()), 'updated_at' => now(),
        ]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.text_finalized', 'seller_turn', $claim->turnId, after: ['version' => $claim->baseVersion + 1]));

        return ['turn_id' => $claim->turnId, 'message_id' => $messageId, 'version' => $claim->baseVersion + 1];
    }
}
