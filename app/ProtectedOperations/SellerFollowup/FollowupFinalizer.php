<?php

namespace App\ProtectedOperations\SellerFollowup;

use App\Ai\Gateway;
use App\Ai\Protected\ApiFollowupInput;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\Policy;
use App\Seller\Engine\ResponderResult;
use App\Seller\Runtime\StageTransitions;
use App\Seller\Settings\Greetings\GreetingResolver;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Writes only server-created fake text after the current turn and history are fenced. */
final class FollowupFinalizer
{
    public function finishProtectedApi(Request $http, TenantContext $tenant, TurnClaim $claim, string $mode,
        int $round = 0, array $toolResults = [], array $guardIssues = [], ?string $page = null): array
    {
        if (! in_array($mode, ['bot', 'suggest'], true)) {
            throw new LogicException('Follow-up mode changed.');
        }
        [, , $state, $goal, $knowledge] = app(ApiFollowupInput::class)->responderLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues);
        $result = app(ResponderResult::class)->interpret(app(Gateway::class)->readApiFollowupResponderLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues));
        if ($result['status'] !== 'candidate') {
            throw new LogicException('API follow-up unavailable.');
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $conversation = DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->lockForUpdate()->first();
        if (! $conversation || $conversation->mode !== $mode) {
            throw new LogicException('Follow-up mode changed.');
        }
        $guard = (new DeterministicGuard($knowledge))->check($result['text'], $goal, $state);
        $decisionEngine = app(GuardDecision::class);
        $enabled = (bool) config('agent.guard_llm', false);
        $model = $decisionEngine->requiresModel($guard, $goal, $enabled)
            ? app(Gateway::class)->readApiFollowupGuardLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues) : null;
        $decision = $decisionEngine->finish($guard, $goal, $enabled, $model);
        if ($decision['status'] !== 'completed' || $decision['verdict'] !== 'ok'
            || ! in_array($goal->kind, ['intro_and_ask', 'ask_slot', 'answer_and_ask', 'answer_only', 'unknown_fact', 'small_talk', 'unclear', 'closed_topic', 'decline_off_topic', 'decline_injection', 'bot_disclosure', 'handoff'], true)) {
            throw new LogicException('API follow-up rejected.');
        }
        if ($goal->slot) {
            $state->markAsked($goal->slot);
            if ($goal->slot === 'budget_range') {
                $state->inc('budget_asks');
            }
        }
        $policy = new Policy($knowledge);
        $state->setOutcome($policy->computeOutcome($state));
        $state->data['missing'] = $policy->computeMissing($state);
        $text = $result['text'];
        if ($tenant->source === 'public' && $mode === 'bot' && $claim->baseVersion === 0) {
            $visitorName = $conversation->contact_id ? DB::table('contacts')->where($scope)->where('id', $conversation->contact_id)->value('name') : null;
            $greeting = app(GreetingResolver::class)->resolve($tenant, 'widget', $page ?? 'home', is_string($visitorName) ? $visitorName : null);
            if (is_string($greeting)) {
                $text = $greeting."\n\n".$text;
            }
        }
        $messageId = $mode === 'bot' ? (string) Str::ulid() : null;
        $metadata = ['fake' => true, 'suggested' => $mode === 'suggest', 'goal_kind' => $goal->kind, 'guard' => 'ok'];
        if ($messageId !== null) {
            DB::table('messages')->insert(['id' => $messageId, ...$scope, 'conversation_id' => $claim->conversationId,
                'role' => 'agent', 'text' => $text, 'meta' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        $status = $mode === 'suggest' ? 'suggested' : 'completed';
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, $status, $claim->baseVersion + 1, $messageId,
            $goal->toArray(), $decision, [], $state->toArray(), [['id' => $messageId, 'role' => 'agent', 'text' => $text, 'meta' => $metadata]]);
        $conversationUpdate = [
            'state' => json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'stage' => $state->stage(),
            'outcome' => $state->outcome(), 'client_type' => $state->clientType(), 'version' => $claim->baseVersion + 1, 'updated_at' => now(),
        ];
        if ($conversation->contact_received_at === null && ($state->has('email') || $state->has('messenger'))) {
            $conversationUpdate['contact_received_at'] = now();
        }
        if ($goal->kind === 'handoff' && $mode === 'bot') {
            $conversationUpdate['mode'] = 'manager';
            $conversationUpdate['handed_off_at'] = now();
        }
        DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->update($conversationUpdate);
        app(StageTransitions::class)->record($tenant, $claim->conversationId, $conversation->stage, $state->stage());
        $data = $outcome->toArray();
        DB::table('seller_turns')->where($scope)->where('id', $claim->turnId)->where('status', 'processing')->update([
            'status' => $status, 'outcome' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'outcome_hash' => CanonicalJson::hash($data), 'updated_at' => now(),
        ]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.'.$status, 'seller_turn', $claim->turnId));
        $output = ['turn_id' => $claim->turnId, 'message_id' => $messageId, 'version' => $claim->baseVersion + 1];
        if ($mode === 'suggest') {
            $output['suggestion'] = $result['text'];
        }

        return ['status' => $status, 'result' => $output];
    }
}
