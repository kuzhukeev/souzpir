<?php

namespace App\Seller\Conversations;

use App\Seller\Channels\Core\ChannelInboundEvent;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\ConversationState;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Conversations\Models\SellerTurn;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class EloquentConversationRepository implements ConversationRepository
{
    public function find(TenantContext $context, string $id): ConversationState
    {
        return app(TenantContextRunner::class)->run($context, function () use ($id): ConversationState {
            $row = Conversation::whereKey($id)->firstOrFail();

            return new ConversationState($row->id, $row->organization_id, $row->brand_id, $row->mode, $row->stage, $row->outcome, $row->client_type, $row->state ?? [], $row->version, $row->channel_account_id, $row->contact_id);
        });
    }

    public function claimTurn(#[\SensitiveParameter] TenantContext $context, #[\SensitiveParameter] TurnCommand $command): TurnClaim
    {
        return PrivateOperation::run(fn () => $this->claimTurnInternal($context, $command));
    }

    private function claimTurnInternal(#[\SensitiveParameter] TenantContext $context, #[\SensitiveParameter] TurnCommand $command): TurnClaim
    {
        if ($context->brandId === null || ! Str::isUlid($command->conversationId) || ! Str::isUlid($command->inputMessageId) || $command->idempotencyKey === '' || mb_strlen($command->idempotencyKey) > 128 || ($command->expectedVersion !== null && $command->expectedVersion < 0)) {
            throw new LogicException('Invalid turn command.');
        }

        return app(TenantContextRunner::class)->run($context, fn () => DB::transaction(function () use ($context, $command): TurnClaim {
            ActiveTenantLock::acquire($context);
            $conversation = Conversation::whereKey($command->conversationId)->lockForUpdate()->firstOrFail();
            $input = Message::whereKey($command->inputMessageId)->where('conversation_id', $conversation->id)->where('role', 'client')->firstOrFail();
            if (! hash_equals($input->text, $command->text)) {
                abort(409, 'Входное сообщение изменилось.');
            }
            if ($conversation->channel_account_id !== null && $input->external_event_id === null) {
                abort(409, 'Channel input requires an inbound event.');
            }
            if ($input->external_event_id !== $command->externalEventId) {
                abort(409, 'Input event does not match message origin.');
            }
            if ($command->externalEventId !== null) {
                if (! Str::isUlid($command->externalEventId)) {
                    abort(404);
                }
                $event = ChannelInboundEvent::whereKey($command->externalEventId)->lockForUpdate()->firstOrFail();
                if ($conversation->channel_account_id === null || $event->channel_account_id !== $conversation->channel_account_id) {
                    abort(404);
                }
            }
            $requestHash = CanonicalJson::hash(['conversationId' => $command->conversationId, 'inputMessageId' => $command->inputMessageId, 'text' => $command->text, 'externalEventId' => $command->externalEventId, 'idempotencyKey' => $command->idempotencyKey, 'expectedVersion' => $command->expectedVersion]);
            $existing = SellerTurn::where('idempotency_key', $command->idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    abort(409, 'Ключ операции уже используется с другими данными.');
                }

                return new TurnClaim($existing->status === 'processing' ? 'in_progress' : 'finished', $existing->id, $existing->conversation_id, $existing->organization_id, $existing->brand_id, $existing->base_version, outcome: $existing->outcome);
            }
            if ($command->externalEventId !== null && SellerTurn::where('external_event_id', $command->externalEventId)->exists()) {
                abort(409, 'Входящее событие уже обработано с другим ключом.');
            }
            if (SellerTurn::where('input_message_id', $input->id)->exists()) {
                abort(409, 'Сообщение уже обработано с другим ключом.');
            }
            if ($command->expectedVersion !== null && $conversation->version !== $command->expectedVersion) {
                abort(409, 'Диалог изменился.');
            }
            if (SellerTurn::where('conversation_id', $conversation->id)->where('status', 'processing')->exists()) {
                abort(409, 'Диалог уже обрабатывается.');
            }
            $token = bin2hex(random_bytes(32));
            $turn = SellerTurn::create(['conversation_id' => $conversation->id, 'input_message_id' => $input->id, 'external_event_id' => $command->externalEventId, 'idempotency_key' => $command->idempotencyKey, 'request_hash' => $requestHash, 'claim_hash' => hash('sha256', $token), 'base_version' => $conversation->version, 'status' => 'processing']);

            return new TurnClaim('claimed', $turn->id, $conversation->id, $context->organizationId, $context->brandId, $conversation->version, $token);
        }));
    }

    public function commitTurn(#[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] TurnOutcome $outcome): void
    {
        PrivateOperation::run(fn () => $this->commitTurnInternal($claim, $outcome));
    }

    private function commitTurnInternal(#[\SensitiveParameter] TurnClaim $claim, #[\SensitiveParameter] TurnOutcome $outcome): void
    {
        if ($claim->status !== 'claimed' || ! is_string($claim->token()) || strlen($claim->token()) !== 64) {
            throw new LogicException('A live claim is required.');
        }
        if ($outcome->turnId !== $claim->turnId || $outcome->conversationId !== $claim->conversationId || $outcome->version !== $claim->baseVersion + 1 || ! in_array($outcome->status, ['completed', 'suggested', 'suppressed', 'failed'], true)) {
            throw new LogicException('Turn outcome does not match claim.');
        }
        if (in_array($outcome->status, ['suppressed', 'failed'], true) && $outcome->messages !== []) {
            throw new LogicException('Suppressed or failed turn cannot create output messages.');
        }
        $context = new TenantContext($claim->organizationId, $claim->brandId, source: 'job');
        app(TenantContextRunner::class)->run($context, fn () => DB::transaction(function () use ($claim, $outcome, $context): void {
            ActiveTenantLock::acquire($context);
            $conversation = Conversation::whereKey($claim->conversationId)->lockForUpdate()->firstOrFail();
            $turn = SellerTurn::whereKey($claim->turnId)->lockForUpdate()->firstOrFail();
            if ($turn->conversation_id !== $claim->conversationId || ! hash_equals($turn->claim_hash, hash('sha256', $claim->token()))) {
                abort(409, 'Заявка на обработку недействительна.');
            }
            $hash = CanonicalJson::hash($outcome->toArray());
            if ($turn->status !== 'processing') {
                if ($turn->outcome_hash && hash_equals($turn->outcome_hash, $hash)) {
                    return;
                }
                abort(409, 'Обработка уже завершена с другим результатом.');
            }
            if ($conversation->version !== $claim->baseVersion || $turn->base_version !== $claim->baseVersion) {
                abort(409, 'Версия диалога изменилась.');
            }
            // A public TurnOutcome and claim do not establish the provenance
            // or authority of action writes. A future protected finalizer must
            // derive these actions from verified server-owned stage records.
            if ($outcome->tasks !== [] || $outcome->events !== []) {
                throw new LogicException('Unverified turn actions are unavailable.');
            }
            $createdMessages = [];
            foreach ($outcome->messages as $message) {
                if (! is_array($message) || ! Str::isUlid($message['id'] ?? '') || ! in_array($message['role'] ?? null, ['agent', 'system', 'tool'], true) || ! is_string($message['text'] ?? null) || ! is_array($message['meta'] ?? [])) {
                    throw new LogicException('Invalid output message.');
                }
                $row = new Message(['conversation_id' => $conversation->id, 'role' => $message['role'], 'text' => $message['text'], 'meta' => $message['meta'] ?? []]);
                $row->id = $message['id'];
                $row->save();
                $createdMessages[$row->id] = $row->role;
            }
            if ($outcome->assistantMessageId !== null && ($createdMessages[$outcome->assistantMessageId] ?? null) !== 'agent') {
                throw new LogicException('Assistant message is not part of this outcome.');
            }
            $conversation->state = $outcome->state;
            $conversation->version = $outcome->version;
            $conversation->save();
            $turn->status = $outcome->status;
            $turn->outcome = $outcome->toArray();
            $turn->outcome_hash = $hash;
            $turn->save();
        }));
    }
}
