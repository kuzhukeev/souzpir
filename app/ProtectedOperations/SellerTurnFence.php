<?php

namespace App\ProtectedOperations;

use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\Data\TurnClaim;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * A low-level, transaction-scoped freshness check, not authorization or provenance.
 * The trusted caller must independently validate actor/session/job permissions and
 * perform its protected database writes before the surrounding transaction ends.
 * Do not call Gateway or any external effect while holding these locks.
 */
final class SellerTurnFence
{
    public function assertCurrent(#[SensitiveParameter] TenantContext $context, #[SensitiveParameter] TurnClaim $claim): void
    {
        try {
            $this->assertCurrentInternal($context, $claim);
        } catch (Throwable) {
            // Never expose claim secrets, source messages or SQL bindings.
            throw new LogicException('Seller turn fence rejected.');
        }
    }

    private function assertCurrentInternal(#[SensitiveParameter] TenantContext $context, #[SensitiveParameter] TurnClaim $claim): void
    {
        $token = $claim->token();
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() < 1
            || $context->organizationId === null || $context->brandId === null
            || $claim->status !== 'claimed' || $claim->organizationId !== $context->organizationId || $claim->brandId !== $context->brandId
            || ! Str::isUlid($claim->turnId) || ! Str::isUlid($claim->conversationId) || $claim->baseVersion < 0
            || ! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new LogicException;
        }

        ActiveTenantLock::acquire($context);
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->lockForUpdate()->first();
        if (! $conversation) {
            throw new LogicException;
        }
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->lockForUpdate()->first();
        if (! $turn || $turn->status !== 'processing' || ! is_string($turn->claim_hash)
            || ! hash_equals($turn->claim_hash, hash('sha256', $token))
            || (int) $turn->base_version !== $claim->baseVersion || (int) $conversation->version !== $claim->baseVersion
            || $conversation->history_ready !== true || $turn->base_history_revision === null || $turn->cutoff_sequence === null
            || (string) $turn->base_history_revision !== (string) $conversation->history_revision
            || (string) $turn->cutoff_sequence !== (string) $conversation->history_sequence) {
            throw new LogicException;
        }
        $inputExists = DB::table('messages')->where('id', $turn->input_message_id)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->where('role', 'client')->where('history_sequence', $turn->cutoff_sequence)->exists();
        if (! $inputExists) {
            throw new LogicException;
        }
    }
}
