<?php

namespace App\ProtectedOperations;

use App\Ai\Gateway;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Internal deterministic check; the returned verdict is not permission to act or send. */
final class FirstTurnDeterministicGuard
{
    /** Caller must hold the transaction for all protected use of the result. */
    public function evaluateLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId): array
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            // This revalidates actor, session, brand, source, result, accounting
            // and freshness and acquires the source and responder locks.
            $candidate = app(Gateway::class)->readProtectedResponderLocked($http, $claim, $responderId);
            if (($candidate['status'] ?? null) !== 'candidate' || ! is_string($candidate['text'] ?? null)) {
                throw new LogicException;
            }
            $stage = DB::table('c02_responder_stages')->where('id', $responderId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
            if (! $stage) {
                throw new LogicException;
            }
            $computed = app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage->extractor_stage_id, $stage->first_state_id);
            $knowledge = app(KnowledgeRepository::class)->published(app(TenantContext::class));
            if ($stage->release_id !== $knowledge->releaseId()) {
                throw new LogicException;
            }

            // No tool has been executed in this protected path; never accept
            // caller-supplied context claiming that an effect already occurred.
            return (new DeterministicGuard($knowledge))->check($candidate['text'], $computed['goal'], new LeadState($computed['state']), ['tools_called' => []]);
        } catch (Throwable) {
            throw new LogicException('Protected first-turn guard unavailable.');
        }
    }
}
