<?php

namespace App\ProtectedOperations\SellerFollowup;

use App\Ai\Gateway;
use App\Ai\Protected\ApiFollowupInput;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Channels\Email\ProtectedPriceSender;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\ResponderResult;
use App\Seller\Engine\ToolRunner;
use App\Seller\Runtime\ConfiguredToolHandler;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Fake-only follow-up and suggestion path; all writes remain in the active session transaction. */
final class FollowupCoordinator
{
    /** Internal fake path for a trusted simulation job; no browser session is involved. */
    public function runJob(TenantContext $tenant, string $conversationId, string $client, string $key): array
    {
        if ($tenant->source !== 'job' || ! $tenant->organizationId || ! $tenant->brandId
            || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Simulation context unavailable.');
        }

        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Simulation requires an independent transaction.');
        }

        return app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $conversationId, $client, $key): array {
            $receipt = app(PostgresTransactionalIdempotency::class)->execute(
                $tenant->organizationId, $tenant->brandId, 'seller.simulation.turn', $key,
                json_encode([$conversationId, $client], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                function () use ($tenant, $conversationId, $client, $key): array {
                    $conversation = DB::table('conversations')->where('id', $conversationId)
                        ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                        ->where('mode', 'bot')->lockForUpdate()->first(['version']);
                    if (! $conversation || (int) $conversation->version < 1
                        || ! DB::table('messages')->where('conversation_id', $conversationId)
                            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                            ->where('role', 'agent')->where('meta->simulation', true)->exists()) {
                        throw new LogicException('Simulation conversation unavailable.');
                    }
                    $messageId = (string) Str::ulid();
                    DB::table('messages')->insert([
                        'id' => $messageId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                        'conversation_id' => $conversationId, 'role' => 'client', 'text' => $client,
                        'meta' => json_encode(['fake' => true, 'simulation' => true], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand(
                        $conversationId, $messageId, $client, null, 'sim:'.hash('sha256', $key), (int) $conversation->version
                    ));
                    if ($claim->status !== 'claimed' || ! is_string($claim->token())) {
                        throw new LogicException('Simulation turn unavailable.');
                    }

                    return ['turn' => $claim->turnId, 'version' => $claim->baseVersion, 'token' => $claim->token()];
                }
            );
            $turn = DB::table('seller_turns')->where('id', $receipt['turn'])->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('conversation_id', $conversationId)->first();
            if ($turn && $turn->status === 'completed') {
                $outcome = json_decode($turn->outcome, true, 512, JSON_THROW_ON_ERROR);
                $assistantId = $outcome['assistantMessageId'] ?? null;
            } else {
                $claim = new TurnClaim('claimed', $receipt['turn'], $conversationId, $tenant->organizationId,
                    $tenant->brandId, $receipt['version'], $receipt['token']);
                $http = Request::create('/internal/simulation', 'POST');
                $result = $this->orchestrateTurn($http, $tenant, $claim, 'bot');
                $assistantId = $result['result']['message_id'] ?? null;
            }
            $agent = is_string($assistantId) ? DB::table('messages')->where('id', $assistantId)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('conversation_id', $conversationId)->value('text') : null;
            if (! is_string($agent) || $agent === '') {
                throw new LogicException('Simulation answer unavailable.');
            }

            return ['agent' => $agent];
        });
    }

    public function run(Request $http, TurnCommand $command, string $mode): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || ! in_array($mode, ['bot', 'suggest'], true)
            || DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            return ['status' => 'unavailable'];
        }
        try {
            $tenant = app(TenantContext::class);
            $receipt = app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $command, $mode, $tenant): array {
                $selected = app(SessionBrandSelection::class)->current($http);
                if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail()
                    || ! $tenant->organizationId || ! $tenant->brandId || ! $tenant->membershipId
                    || $selected->selected_organization_id !== $tenant->organizationId || $selected->selected_brand_id !== $tenant->brandId
                    || (string) $selected->brand_revision !== $http->attributes->get('tenant.brand_revision')) {
                    throw new LogicException('Context changed.');
                }
                $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                    ->where('user_id', $user->id)->where('status', 'active')->first();
                if (! $member || ! $member->permitsBrand($tenant->brandId)
                    || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller')
                    || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                    throw new LogicException('Access changed.');
                }
                $conversation = DB::table('conversations')->where('id', $command->conversationId)
                    ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first(['mode', 'version']);
                if (! $conversation || $conversation->mode !== $mode || ($mode === 'bot' && (int) $conversation->version === 0)) {
                    throw new LogicException('Follow-up unavailable.');
                }
                $digest = hash_hmac('sha256', "protected-operation-key-v1\0".$command->idempotencyKey, (string) config('app.key'));
                $hasReceipt = DB::table('protected_operation_receipts')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('operation', 'seller.session.followup.claim')->where('key_digest', $digest)->exists();
                if (! $hasReceipt && DB::table('seller_turns')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->where('idempotency_key', $command->idempotencyKey)->exists()) {
                    $existing = app(ConversationRepository::class)->claimTurn($tenant, $command);
                    if ($existing->status === 'finished') {
                        return ['turn' => $existing->turnId, 'finished' => true];
                    }
                    if (in_array($existing->status, ['in_progress', 'uncertain'], true)) {
                        return ['turn' => $existing->turnId, 'pending' => $existing->status];
                    }
                }
                if (! $hasReceipt && $command->expectedVersion !== (int) $conversation->version) {
                    throw new IdempotencyConflict('Turn key or revision changed.');
                }
                $payload = json_encode(['conversation' => $command->conversationId, 'input' => $command->inputMessageId,
                    'text' => $command->text, 'event' => $command->externalEventId, 'version' => $command->expectedVersion, 'mode' => $mode], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                    'seller.session.followup.claim', $command->idempotencyKey, $payload, function () use ($tenant, $command): array {
                        $claim = app(ConversationRepository::class)->claimTurn($tenant, $command);
                        if ($claim->status === 'finished') {
                            return ['turn' => $claim->turnId, 'finished' => true];
                        }
                        if ($claim->status !== 'claimed' || $claim->baseVersion !== $command->expectedVersion || ! is_string($claim->token())) {
                            throw new LogicException('Invalid follow-up claim.');
                        }

                        return ['turn' => $claim->turnId, 'version' => $claim->baseVersion, 'token' => $claim->token()];
                    });
            });
            $turn = DB::table('seller_turns')->where('id', $receipt['turn'])->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('conversation_id', $command->conversationId)->first();
            if (isset($receipt['pending'])) {
                return ['status' => $receipt['pending']];
            }
            if ($turn && in_array($turn->status, ['completed', 'suggested'], true)) {
                $outcome = json_decode($turn->outcome, true, 512, JSON_THROW_ON_ERROR);
                $result = ['turn_id' => $turn->id, 'message_id' => $outcome['assistantMessageId'] ?? null, 'version' => $outcome['version'] ?? null];
                if ($turn->status === 'suggested') {
                    $result['suggestion'] = $outcome['messages'][0]['text'] ?? null;
                }

                return ['status' => $turn->status, 'result' => $result];
            }
            if (! is_string($receipt['token'] ?? null) || ! is_int($receipt['version'] ?? null)) {
                throw new LogicException('Invalid follow-up claim.');
            }
            $claim = new TurnClaim('claimed', $receipt['turn'], $command->conversationId, $tenant->organizationId, $tenant->brandId, $receipt['version'], $receipt['token']);

            return $this->orchestrateTurn($http, $tenant, $claim, $mode);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 409) {
                throw new IdempotencyConflict('Turn key or revision changed.');
            }

            throw $error;
        }
    }

    /** Run extractor and responder rounds, then finalize in its own short transaction. */
    public function orchestrateTurn(Request $http, TenantContext $tenant, TurnClaim $claim, string $mode): array
    {
        [$round, $toolResults, $guardIssues] = $this->prepareFinalRound($http, $tenant, $claim);

        return DB::transaction(fn () => app(FollowupFinalizer::class)->finishProtectedApi($http, $tenant, $claim, $mode, $round, $toolResults, $guardIssues));
    }

    /**
     * Run extractor, then a bounded responder loop: execute an offered tool round once and
     * feed results back, or regenerate on a deterministic/model guard rejection; stop once ok.
     * The caller finalizes with the returned [round, toolResults, guardIssues], e.g. atomically with delivery.
     *
     * @return array{0: int, 1: array, 2: array}
     */
    public function prepareFinalRound(Request $http, TenantContext $tenant, TurnClaim $claim): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::transactionLevel() !== 0) {
            throw new LogicException('API follow-up unavailable.');
        }
        $gateway = app(Gateway::class);
        $gateway->completeApiFollowupExtractor($http, $tenant, $claim);
        $toolResults = [];
        $guardIssues = [];
        for ($round = 0; $round < 3; $round++) {
            $gateway->completeApiFollowupResponder($http, $tenant, $claim, $round, $toolResults, $guardIssues);
            $decision = DB::transaction(function () use ($http, $tenant, $claim, $round, $toolResults, $guardIssues, $gateway): array {
                $interpreted = app(ResponderResult::class)->interpret($gateway->readApiFollowupResponderLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues));
                if ($interpreted['status'] === 'tool_calls') {
                    return ['status' => 'tool_calls'];
                }
                if ($interpreted['status'] !== 'candidate') {
                    throw new LogicException('API follow-up unavailable.');
                }
                [, , $state, $goal, $knowledge] = app(ApiFollowupInput::class)->responderLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues);
                $deterministic = (new DeterministicGuard($knowledge))->check($interpreted['text'], $goal, $state);

                return ['status' => 'candidate', 'ok' => $deterministic['verdict'] === 'ok',
                    'needs_model' => app(GuardDecision::class)->requiresModel($deterministic, $goal, (bool) config('agent.guard_llm', false)),
                    'issues' => $deterministic['issues']];
            });
            if ($decision['status'] === 'tool_calls') {
                $toolResults = $this->runProtectedTools($http, $tenant, $claim, $round, $toolResults, $gateway);
                if ($toolResults === []) {
                    throw new LogicException('API follow-up unavailable.');
                }
                $guardIssues = [];

                continue;
            }
            if (! $decision['ok']) {
                if ($round >= 2) {
                    throw new LogicException('API follow-up unavailable.');
                }
                $toolResults = [];
                $guardIssues = array_slice($decision['issues'], 0, 8);

                continue;
            }
            if ($decision['needs_model']) {
                $gateway->completeApiFollowupGuard($http, $tenant, $claim, $round, $toolResults, $guardIssues);
                $model = DB::transaction(fn () => $gateway->readApiFollowupGuardLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues));
                if ($model['status'] !== 'evaluated') {
                    throw new LogicException('API follow-up unavailable.');
                }
                if ($model['verdict'] !== true) {
                    if ($round >= 2) {
                        throw new LogicException('API follow-up unavailable.');
                    }
                    $toolResults = [];
                    $guardIssues = array_slice($model['issues'], 0, 8);

                    continue;
                }
            }

            return [$round, $toolResults, $guardIssues];
        }

        throw new LogicException('API follow-up unavailable.');
    }

    /** Execute at most one bounded round of tool calls offered by the given responder round. */
    private function runProtectedTools(Request $http, TenantContext $tenant, TurnClaim $claim, int $round, array $requestedToolResults, Gateway $gateway): array
    {
        $results = [];
        foreach (range(0, 3) as $index) {
            try {
                $call = DB::transaction(fn () => $gateway->readApiFollowupToolCallLocked($http, $tenant, $claim, $round, $requestedToolResults, $index));
            } catch (Throwable) {
                break;
            }
            $name = $call['name'];
            $result = match ($name) {
                'calc_delivery' => app(ConfiguredToolHandler::class)->calculateDelivery($tenant, $call['arguments']),
                'send_price' => app(ProtectedPriceSender::class)->send($tenant, $claim, 'api-followup-responder-'.$claim->turnId.'-'.$round, $call['id'], $call['arguments']),
                default => app(ToolRunner::class)->run($name, $call['arguments'], $tenant),
            };
            $results[] = ['id' => $call['id'], 'name' => $name, 'result' => $result];
        }

        return $results;
    }
}
