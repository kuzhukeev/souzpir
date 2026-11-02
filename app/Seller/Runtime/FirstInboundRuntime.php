<?php

namespace App\Seller\Runtime;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\FirstTurnCoordinator;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
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
use SensitiveParameter;
use Throwable;

/** First inbound fake-only runtime; subsequent turns and delivery remain separate. */
final class FirstInboundRuntime
{
    public function turn(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnCommand $command): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || ! Str::isUlid($command->conversationId)
                || ! Str::isUlid($command->inputMessageId) || $command->idempotencyKey === '' || strlen($command->idempotencyKey) > 512) {
                return ['status' => 'unavailable'];
            }
            $dispatch = app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $command): array {
                $tenant = app(TenantContext::class);
                $selection = app(SessionBrandSelection::class)->current($http);
                $revision = $http->attributes->get('tenant.brand_revision');
                if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail()
                    || ! $tenant->organizationId || ! $tenant->brandId || ! $tenant->membershipId
                    || ! is_string($revision) || $revision !== (string) $selection->brand_revision
                    || $selection->selected_organization_id !== $tenant->organizationId || $selection->selected_brand_id !== $tenant->brandId) {
                    throw new \LogicException;
                }
                $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                    ->where('user_id', $user->id)->where('status', 'active')->first();
                if (! $member || ! $member->permitsBrand($tenant->brandId)
                    || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                    throw new \LogicException;
                }
                $conversation = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->where('id', $command->conversationId)->first(['id', 'mode', 'version']);
                $message = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->where('conversation_id', $command->conversationId)->where('id', $command->inputMessageId)->where('role', 'client')
                    ->first(['text', 'external_event_id']);
                if (! $conversation || ! $message || ! hash_equals($message->text, $command->text)
                    || $message->external_event_id !== $command->externalEventId) {
                    throw new \LogicException;
                }
                if ($conversation->mode === 'manager') {
                    return ['mode' => 'manager', 'result' => $this->suppressManagerTurn($tenant, $command)];
                }

                return ['mode' => $conversation->mode];
            });
            if ($dispatch['mode'] === 'manager') {
                return $dispatch['result'];
            }
            if ($dispatch['mode'] !== 'bot') {
                return ['status' => 'unavailable'];
            }

            return app(FirstTurnCoordinator::class)->run($http, $command);
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Idempotency key was used with different content.');
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    private function suppressManagerTurn(#[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] TurnCommand $command): array
    {
        if (DB::connection()->transactionLevel() < 1 || ! $tenant->organizationId || ! $tenant->brandId) {
            throw new \LogicException;
        }
        $claim = app(ConversationRepository::class)->claimTurn($tenant, $command);
        if ($claim->status === 'finished') {
            if (($claim->outcome['status'] ?? null) !== 'suppressed') {
                throw new \LogicException;
            }

            return ['status' => 'suppressed', 'turn_id' => $claim->turnId, 'version' => $claim->baseVersion + 1];
        }
        if (in_array($claim->status, ['in_progress', 'uncertain'], true)) {
            return ['status' => $claim->status];
        }
        if ($claim->status !== 'claimed' || ! is_string($claim->token())) {
            throw new \LogicException;
        }
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('conversation_id', $claim->conversationId)->lockForUpdate()->first();
        if (! $conversation || ! $turn || $conversation->mode !== 'manager' || $turn->status !== 'processing'
            || (int) $conversation->version !== $claim->baseVersion || ! hash_equals($turn->claim_hash, hash('sha256', $claim->token()))
            || ! $conversation->history_ready || (string) $turn->base_history_revision !== (string) $conversation->history_revision
            || (string) $turn->cutoff_sequence !== (string) $conversation->history_sequence || $turn->input_message_id !== $command->inputMessageId) {
            throw new \LogicException;
        }
        $state = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new \LogicException;
        }
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, 'suppressed', $claim->baseVersion + 1, null, null, null, [], $state);
        $data = $outcome->toArray();
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'suppressed',
            'outcome' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'outcome_hash' => CanonicalJson::hash($data), 'updated_at' => now()]);
        DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->update(['version' => $claim->baseVersion + 1, 'updated_at' => now()]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.suppressed', 'seller_turn', $claim->turnId));

        return ['status' => 'suppressed', 'turn_id' => $claim->turnId, 'version' => $claim->baseVersion + 1];
    }
}
