<?php

namespace App\ProtectedOperations;

use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** A server-side job may reserve the persisted first input without a web session. */
final class JobFirstTurnClaim
{
    public function claim(TenantContext $tenant, string $conversationId, string $messageId, string $idempotencyKey): TurnClaim
    {
        if ($tenant->source !== 'job' || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Job claim unavailable.');
        }

        return app(TenantContextRunner::class)->run($tenant, fn () => DB::transaction(function () use ($tenant, $conversationId, $messageId, $idempotencyKey): TurnClaim {
            $command = app(JobFirstTurnInput::class)->resolve($tenant, $conversationId, $messageId, $idempotencyKey);
            $conversation = DB::table('conversations')->where('id', $conversationId)->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->first();
            $message = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->first();
            if (! $conversation || ! $message || $conversation->history_ready !== true
                || (int) $conversation->history_revision !== 1 || (int) $conversation->history_sequence !== 1
                || (int) $message->history_sequence !== 1) {
                throw new LogicException('Job claim unavailable.');
            }
            $claim = app(ConversationRepository::class)->claimTurn($tenant, $command);
            if ($claim->status !== 'claimed' || ! is_string($claim->token())) {
                throw new LogicException('Job claim unavailable.');
            }
            DB::table('e306g_job_turn_claims')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $claim->organizationId,
                'brand_id' => $claim->brandId, 'conversation_id' => $claim->conversationId,
                'turn_id' => $claim->turnId, 'claim_hash' => hash('sha256', $claim->token()),
            ]);

            return $claim;
        }));
    }

    public function assertCurrent(TenantContext $tenant, TurnClaim $claim): void
    {
        if ($tenant->source !== 'job' || config('runtime.ai_driver', 'fake') !== 'fake' || DB::transactionLevel() < 1) {
            throw new LogicException('Job claim unavailable.');
        }
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $row = DB::table('e306g_job_turn_claims')->where('turn_id', $claim->turnId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('conversation_id', $claim->conversationId)->first();
        if (! $row || ! is_string($claim->token()) || ! hash_equals($row->claim_hash, hash('sha256', $claim->token()))) {
            throw new LogicException('Job claim unavailable.');
        }
        app(JobFirstTurnInput::class)->resolve($tenant, $claim->conversationId,
            DB::table('seller_turns')->where('id', $claim->turnId)->value('input_message_id'), 'verify');
    }
}
