<?php

namespace App\ProtectedOperations;

use App\Ai\Gateway;
use App\Ai\Protected\SellerExtractorStages;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Internal computation, not authority to persist or execute the returned goal. */
final class FirstTurnGoal
{
    /**
     * The caller must keep the outer transaction open throughout any protected use.
     *
     * @return array{goal: Goal, state: array}
     */
    public function evaluateLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $stateId): array
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }

            // Each source is verified again; neither a state nor a goal supplied
            // by the caller is accepted as evidence of origin or authority.
            $state = app(FirstTurnState::class)->readLocked($http, $claim, $stageId, $stateId);
            $extraction = app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stageId);
            [, , , $callId, , , $message] = app(SellerExtractorStages::class)->load($http, $claim, $stageId);
            $stage = DB::table('c02_extractor_stages')->where('id', $stageId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
            if (! $stage || ! is_string($callId) || $stage->ai_call_id !== $callId || ! is_string($message)) {
                throw new LogicException;
            }
            $knowledge = app(KnowledgeRepository::class)->forRelease(app(TenantContext::class), $stage->release_id, false);
            if (! hash_equals($stage->release_checksum, $knowledge->checksum())) {
                throw new LogicException;
            }

            $lead = new LeadState($state);
            $extraction['_text'] = mb_strtolower($message);
            $goal = (new Policy($knowledge))->decide($lead, $extraction, true);

            // Return the full Goal, including facts and closed-topic data that
            // Goal::toArray() intentionally omits, and Policy's state changes.
            // These mutable values alone do not authorize an effect.
            return ['goal' => $goal, 'state' => $lead->toArray()];
        } catch (Throwable) {
            throw new LogicException('Protected first-turn goal unavailable.');
        }
    }
}
