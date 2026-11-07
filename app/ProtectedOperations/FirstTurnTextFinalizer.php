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
use App\Seller\Runtime\ServicePhraseSelector;
use App\Seller\Settings\Greetings\GreetingResolver;
use App\Seller\Settings\Schedule\Availability;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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

            return app(AuthSessionRegistry::class)->withinActive($http,
                fn (User $user): array => $this->executeLocked($http, $user, $claim, $responderId, $modelGuardId, $idempotencyKey, true));
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Idempotency key was used with different content.');
        } catch (Throwable) {
            throw new LogicException('Protected first-turn finalization unavailable.');
        }
    }

    /** Read an existing sealed result inside the caller's transaction; never perform a new write. */
    public function replayLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId, #[SensitiveParameter] ?string $modelGuardId, #[SensitiveParameter] string $idempotencyKey): array
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() < 1
                || ! Str::isUlid($responderId) || ($modelGuardId !== null && ! Str::isUlid($modelGuardId))
                || $idempotencyKey === '' || strlen($idempotencyKey) > 512) {
                throw new LogicException;
            }

            return app(AuthSessionRegistry::class)->withinActive($http,
                fn (User $user): array => $this->executeLocked($http, $user, $claim, $responderId, $modelGuardId, $idempotencyKey, false));
        } catch (Throwable) {
            throw new LogicException('Protected first-turn finalization unavailable.');
        }
    }

    private function executeLocked(#[SensitiveParameter] Request $http, User $user, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId, #[SensitiveParameter] ?string $modelGuardId, #[SensitiveParameter] string $idempotencyKey, bool $allowWrite): array
    {
        [$tenant, $selection] = $this->authorize($http, $user, $claim);
        $originStatus = app(FirstTurnProtectedClaim::class)->assertOriginLocked($http, $claim);
        if (! in_array($originStatus, $allowWrite ? ['processing', 'completed'] : ['completed'], true)) {
            throw new LogicException;
        }
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new LogicException;
        }
        $requestHash = CanonicalJson::hash(['turn' => $claim->turnId, 'conversation' => $claim->conversationId,
            'base_version' => $claim->baseVersion, 'responder' => $responderId, 'model_guard' => $modelGuardId,
            'actor' => $user->id, 'session' => hash_hmac('sha256', 'c02-finalizer-session:'.$http->session()->getId(), $secret),
            'selection_revision' => (string) $selection->brand_revision, 'claim' => hash_hmac('sha256', 'c02-finalizer-claim:'.$claim->token(), $secret)]);

        $result = app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.first_text_finalize',
            $idempotencyKey, $requestHash, function () use ($allowWrite, $http, $tenant, $claim, $responderId, $modelGuardId): array {
                if (! $allowWrite) {
                    throw new LogicException;
                }

                return $this->write($http, $tenant, $claim, $responderId, $modelGuardId);
            });
        $this->verifyReceipt($tenant, $claim, $result);
        unset($result['outcome_seal']);

        return $result;
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
            || ! in_array($goal->kind, ['intro_and_ask', 'ask_slot', 'answer_and_ask', 'answer_only', 'unknown_fact', 'small_talk', 'unclear', 'closed_topic', 'decline_off_topic', 'decline_injection', 'bot_disclosure', 'handoff'], true)
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
        $metadata = ['intent' => $extraction['client_intent'] ?? null, 'goal_kind' => $goal->kind, 'guard' => $guard['verdict'] ?? null];
        $inputId = DB::table('seller_turns')->where('id', $claim->turnId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->value('input_message_id');
        $input = DB::table('messages')->where('id', $inputId)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('role', 'client')->first() ?? throw new LogicException;
        $previousMeta = json_decode($input->meta ?: '{}', true) ?: [];
        $replyText = $candidate['text'];
        if (in_array($goal->kind, ['unknown_fact', 'handoff'], true)) {
            $override = app(ServicePhraseSelector::class)->customForEvent($knowledge->persona(), $goal->kind);
            if ($override !== null) {
                $replyText = $override;
            }
        }
        if (! in_array($goal->kind, ['decline_injection', 'decline_off_topic', 'closed_topic', 'handoff'], true)) {
            $conversation = DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->first(['channel_account_id', 'contact_id']) ?? throw new LogicException;
            $channel = $conversation->channel_account_id ? DB::table('channel_accounts')->where('id', $conversation->channel_account_id)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('type') : 'api';
            $name = $conversation->contact_id ? DB::table('contacts')->where('id', $conversation->contact_id)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('name') : null;
            $brand = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->first(['timezone']);
            $schedule = DB::table('brand_seller_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('schedule');
            $settings = is_string($schedule) ? json_decode($schedule, true) : [];
            $afterHours = $brand && is_array($settings) && app(Availability::class)->forTime($settings, CarbonImmutable::now(), $brand->timezone)['mode'] === 'off';
            $eventPayload = $input->external_event_id ? DB::table('channel_inbound_events')->where('id', $input->external_event_id)
                ->where('channel_account_id', $conversation->channel_account_id)->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->value('payload') : null;
            $eventData = is_string($eventPayload) ? json_decode($eventPayload, true) : [];
            $pageValue = $previousMeta['page'] ?? $eventData['page'] ?? 'home';
            $page = is_string($pageValue) ? $pageValue : 'home';
            $greeting = app(GreetingResolver::class)->resolve($tenant, is_string($channel) ? $channel : '', $page, is_string($name) ? $name : null, $afterHours);
            if ($greeting !== null) {
                $replyText = $greeting."\n\n".$replyText;
            }
        }
        DB::table('messages')->where('id', $inputId)->update(['meta' => json_encode([...$previousMeta, ...$metadata], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $messageId = (string) Str::ulid();
        $message = ['id' => $messageId, 'role' => 'agent', 'text' => $replyText, 'meta' => $metadata];
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, 'completed', $claim->baseVersion + 1, $messageId,
            $goal->toArray(), $guard, [], $state->toArray(), [$message]);
        DB::table('messages')->insert(['id' => $messageId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $claim->conversationId, 'role' => 'agent', 'text' => $replyText, 'meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $conversationUpdate = [
            'state' => json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'stage' => $state->stage(), 'outcome' => $state->outcome(),
            'client_type' => $state->clientType(), 'version' => $claim->baseVersion + 1, 'updated_at' => now(),
        ];
        if ($state->has('email') || $state->has('messenger')) {
            $conversationUpdate['contact_received_at'] = now();
        }
        if ($goal->kind === 'handoff') {
            $conversationUpdate['mode'] = 'manager';
            $conversationUpdate['handed_off_at'] = now();
        }
        DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->update($conversationUpdate);
        $encoded = json_encode($outcome->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $outcomeHash = \App\Seller\Conversations\CanonicalJson::hash($outcome->toArray());
        DB::table('seller_turns')->where('id', $claim->turnId)->where('status', 'processing')->update([
            'status' => 'completed', 'outcome' => $encoded, 'outcome_hash' => $outcomeHash, 'updated_at' => now(),
        ]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.text_finalized', 'seller_turn', $claim->turnId, after: ['version' => $claim->baseVersion + 1]));

        return ['turn_id' => $claim->turnId, 'message_id' => $messageId, 'version' => $claim->baseVersion + 1,
            'outcome_seal' => hash_hmac('sha256', 'c02-first-text-outcome:'.$outcomeHash, (string) config('app.key'))];
    }

    private function verifyReceipt(#[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] array $result): void
    {
        if (count($result) !== 4 || array_diff(array_keys($result), ['turn_id', 'message_id', 'version', 'outcome_seal']) !== []
            || ($result['turn_id'] ?? null) !== $claim->turnId || ! is_string($result['message_id'] ?? null)
            || ! Str::isUlid($result['message_id']) || ($result['version'] ?? null) !== $claim->baseVersion + 1
            || ! is_string($result['outcome_seal'] ?? null)) {
            throw new LogicException;
        }
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        if (! $turn || $turn->status !== 'completed' || ! is_string($turn->outcome) || ! is_string($turn->outcome_hash)) {
            throw new LogicException;
        }
        $outcome = json_decode($turn->outcome, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($outcome) || ! hash_equals($turn->outcome_hash, \App\Seller\Conversations\CanonicalJson::hash($outcome))
            || ! hash_equals($result['outcome_seal'], hash_hmac('sha256', 'c02-first-text-outcome:'.$turn->outcome_hash, (string) config('app.key')))
            || ($outcome['turnId'] ?? null) !== $claim->turnId || ($outcome['conversationId'] ?? null) !== $claim->conversationId
            || ($outcome['status'] ?? null) !== 'completed' || ($outcome['version'] ?? null) !== $result['version']
            || ($outcome['assistantMessageId'] ?? null) !== $result['message_id']
            || ! is_array($outcome['messages'] ?? null) || count($outcome['messages']) !== 1
            || ($outcome['messages'][0]['id'] ?? null) !== $result['message_id'] || ($outcome['messages'][0]['role'] ?? null) !== 'agent'
            || ! is_string($outcome['messages'][0]['text'] ?? null)) {
            throw new LogicException;
        }
        $message = DB::table('messages')->where('id', $result['message_id'])->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('role', 'agent')->first();
        if (! $message || ! hash_equals($outcome['messages'][0]['text'], $message->text)) {
            throw new LogicException;
        }
    }
}
