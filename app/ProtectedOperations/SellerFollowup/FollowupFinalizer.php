<?php

namespace App\ProtectedOperations\SellerFollowup;

use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Writes only server-created fake text after the current turn and history are fenced. */
final class FollowupFinalizer
{
    public function finish(TenantContext $tenant, TurnClaim $claim, string $mode): array
    {
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        if (! $conversation || $conversation->mode !== $mode || ! in_array($mode, ['bot', 'suggest'], true)
            || ($mode === 'bot' && $claim->baseVersion === 0)) {
            throw new LogicException('Follow-up mode changed.');
        }
        $state = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new LogicException('Invalid conversation state.');
        }
        $text = $mode === 'suggest'
            ? 'Предложите клиенту уточнить детали запроса.'
            : 'Спасибо за сообщение. Уточните, пожалуйста, детали запроса.';
        $messageId = $mode === 'bot' ? (string) Str::ulid() : null;
        $candidate = ['role' => 'agent', 'text' => $text, 'meta' => ['fake' => true, 'suggested' => $mode === 'suggest']];
        if ($messageId !== null) {
            DB::table('messages')->insert(['id' => $messageId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'conversation_id' => $claim->conversationId, 'role' => 'agent', 'text' => $text,
                'meta' => json_encode($candidate['meta'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        $status = $mode === 'suggest' ? 'suggested' : 'completed';
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, $status, $claim->baseVersion + 1,
            $messageId, null, null, [], $state, [$candidate + ['id' => $messageId]]);
        DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->update(['version' => $claim->baseVersion + 1, 'updated_at' => now()]);
        DB::table('seller_turns')->where('id', $claim->turnId)->where('status', 'processing')->update([
            'status' => $status, 'outcome' => json_encode($outcome->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'outcome_hash' => CanonicalJson::hash($outcome->toArray()), 'updated_at' => now(),
        ]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.'.$status, 'seller_turn', $claim->turnId));

        $result = ['turn_id' => $claim->turnId, 'message_id' => $messageId, 'version' => $claim->baseVersion + 1];
        if ($mode === 'suggest') {
            $result['suggestion'] = $text;
        }

        return ['status' => $status, 'result' => $result];
    }
}
