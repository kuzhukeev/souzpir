<?php

namespace App\ProtectedOperations\SellerFollowup;

use App\Ai\Gateway;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
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

        return app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $conversationId, $client, $key): array {
            $conversation = DB::table('conversations')->where('id', $conversationId)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('mode', 'bot')->first(['version']);
            if (! $conversation || (int) $conversation->version < 1
                || DB::connection()->transactionLevel() < 1
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
            $claim = app(ConversationRepository::class)->claimTurn(
                $tenant, new TurnCommand($conversationId, $messageId, $client, null, $key, (int) $conversation->version)
            );
            if ($claim->status !== 'claimed') {
                throw new LogicException('Simulation turn unavailable.');
            }
            $outcome = app(FollowupFinalizer::class)->finish($tenant, $claim, 'bot');
            $assistantId = $outcome['result']['message_id'] ?? null;
            $agent = is_string($assistantId)
                ? DB::table('messages')->where('id', $assistantId)->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('conversation_id', $conversationId)->value('text')
                : null;
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
            app(Gateway::class)->completeApiFollowupExtractor($http, $tenant, $claim);
            app(Gateway::class)->completeApiFollowupResponder($http, $tenant, $claim);

            return DB::transaction(fn () => app(FollowupFinalizer::class)->finishProtectedApi($http, $tenant, $claim, $mode));
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 409) {
                throw new IdempotencyConflict('Turn key or revision changed.');
            }

            throw $error;
        }
    }
}
