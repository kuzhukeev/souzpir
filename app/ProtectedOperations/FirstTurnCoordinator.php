<?php

namespace App\ProtectedOperations;

use App\Ai\Gateway;
use App\Ai\Protected\SellerExtractorStages;
use App\Ai\Protected\SellerGuardStages;
use App\Ai\Protected\SellerResponderStages;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Engine\GuardDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Internal fake-only first inbound orchestration; never delivers or runs tools. */
final class FirstTurnCoordinator
{
    public static function finalizationKey(string $turnId): string
    {
        if (! Str::isUlid($turnId)) {
            throw new LogicException('Protected first-turn coordination unavailable.');
        }

        return 'c02-coordinator-first-v1-'.$turnId;
    }

    /** @return array{status:string,result?:array} */
    public function run(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnCommand $command): array
    {
        $claimIssued = false;
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql'
                || DB::connection()->transactionLevel() !== 0) {
                throw new LogicException;
            }
            $claims = app(FirstTurnProtectedClaim::class);
            $claim = $claims->claim($http, $command);
            $claimIssued = true;
            if ($claim->status === 'uncertain') {
                return $this->requestReview($http, $claim->turnId);
            }
            if ($claim->status === 'finished') {
                return ['status' => 'completed', 'result' => $claims->readFinishedCoordinatorResult($http, $command)];
            }
            if ($claim->status !== 'claimed') {
                throw new LogicException;
            }
            $extractor = app(SellerExtractorStages::class)->prepare($http, $claim);
            $gateway = app(Gateway::class);
            $status = $gateway->completeProtectedExtractor($http, $claim, $extractor);
            if ($status === 'in_progress') {
                try {
                    // Only a validated saved fake result can be settled; an
                    // absent or unverified result must never trigger a retry.
                    $status = $gateway->settleSavedFakeExtractor($http, $claim, $extractor);
                } catch (Throwable) {
                    return ['status' => 'in_progress'];
                }
            }
            if ($status !== 'completed') {
                return $status === 'uncertain' ? $this->requestReview($http, $claim->turnId) : $this->pending($status);
            }
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $extractor);
            $responder = app(SellerResponderStages::class)->prepare($http, $claim, $extractor, $stateId);
            $status = $gateway->completeProtectedResponder($http, $claim, $responder);
            if ($status === 'in_progress') {
                try {
                    // Only the saved, signed fake result may be settled; tool
                    // signals remain data for the existing Guard boundary.
                    $status = $gateway->settleSavedFakeResponder($http, $claim, $responder);
                } catch (Throwable) {
                    return ['status' => 'in_progress'];
                }
            }
            if ($status !== 'completed') {
                return $status === 'uncertain' ? $this->requestReview($http, $claim->turnId) : $this->pending($status);
            }
            $check = DB::transaction(function () use ($http, $claim, $extractor, $stateId, $responder, $gateway): array {
                $candidate = $gateway->readProtectedResponderLocked($http, $claim, $responder);
                if (($candidate['status'] ?? null) === 'tool_calls') {
                    return ['status' => 'stopped_tool'];
                }
                if (($candidate['status'] ?? null) !== 'candidate') {
                    return ['status' => 'blocked'];
                }
                $computed = app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $extractor, $stateId);
                $deterministic = app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $responder);
                if (($deterministic['verdict'] ?? null) !== 'ok') {
                    return ['status' => 'blocked'];
                }
                $enabled = config('agent.guard_llm', false);
                if (! is_bool($enabled)) {
                    throw new LogicException;
                }

                return ['status' => 'ready', 'needs_model' => app(GuardDecision::class)->requiresModel($deterministic, $computed['goal'], $enabled)];
            });
            if ($check['status'] === 'stopped_tool') {
                return $this->requestReview($http, $claim->turnId);
            }
            if ($check['status'] !== 'ready') {
                return $this->requestReview($http, $claim->turnId);
            }
            $guard = null;
            if ($check['needs_model']) {
                $guard = app(SellerGuardStages::class)->prepare($http, $claim, $responder);
                $status = $gateway->completeProtectedModelGuard($http, $claim, $guard);
                if ($status !== 'completed') {
                    return $status === 'uncertain' ? $this->requestReview($http, $claim->turnId) : $this->pending($status);
                }
                $decision = DB::transaction(fn () => $gateway->readProtectedModelGuardLocked($http, $claim, $guard));
                if (($decision['status'] ?? null) !== 'completed' || ($decision['verdict'] ?? null) !== 'ok') {
                    return $this->requestReview($http, $claim->turnId);
                }
            }
            $result = app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $responder, $guard, self::finalizationKey($claim->turnId));

            return ['status' => 'completed', 'result' => $result];
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Idempotency key was used with different content.');
        } catch (Throwable) {
            // A competing finalizer or new input can win between short stages.
            // Re-read only an already-created claim; never start another call.
            if ($claimIssued) {
                try {
                    $current = app(FirstTurnProtectedClaim::class)->claim($http, $command);
                    if ($current->status === 'uncertain') {
                        return $this->requestReview($http, $current->turnId);
                    }
                    if ($current->status === 'finished') {
                        return ['status' => 'completed', 'result' => app(FirstTurnProtectedClaim::class)->readFinishedCoordinatorResult($http, $command)];
                    }
                } catch (Throwable) {
                    // The session, rights or saved result may have changed.
                }
            }

            return ['status' => 'unavailable'];
        }
    }

    private function pending(string $status): array
    {
        return ['status' => in_array($status, ['in_progress', 'uncertain'], true) ? $status : 'unavailable'];
    }

    private function requestReview(#[SensitiveParameter] Request $http, #[SensitiveParameter] string $turnId): array
    {
        app(ManualReviewRequests::class)->quarantineForCoordinator($http, $turnId);

        return ['status' => 'uncertain'];
    }
}
