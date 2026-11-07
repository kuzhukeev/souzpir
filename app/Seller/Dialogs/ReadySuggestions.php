<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Seller\Conversations\CanonicalJson;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class ReadySuggestions
{
    public function read(TenantContext $tenant, string $actorId, Membership $member): array
    {
        $query = DB::table('seller_turns as t')
            ->join('conversations as c', function ($join): void {
                $join->on('c.id', '=', 't.conversation_id')->on('c.brand_id', '=', 't.brand_id')->on('c.organization_id', '=', 't.organization_id');
            })->join('messages as input', function ($join): void {
                $join->on('input.id', '=', 't.input_message_id')->on('input.conversation_id', '=', 'c.id')->on('input.brand_id', '=', 'c.brand_id')->on('input.organization_id', '=', 'c.organization_id');
            })->leftJoin('contacts as contact', function ($join): void {
                $join->on('contact.id', '=', 'c.contact_id')->on('contact.brand_id', '=', 'c.brand_id')->on('contact.organization_id', '=', 'c.organization_id');
            })->leftJoin('channel_accounts as channel', function ($join): void {
                $join->on('channel.id', '=', 'c.channel_account_id')->on('channel.brand_id', '=', 'c.brand_id')->on('channel.organization_id', '=', 'c.organization_id');
            })->where('t.organization_id', $tenant->organizationId)->where('t.brand_id', $tenant->brandId)
            ->where('t.status', 'suggested')->where('c.mode', 'suggest')
            ->whereColumn('t.cutoff_sequence', 'c.history_sequence')->whereRaw('c.history_revision = t.base_history_revision + 1');
        if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false)) {
            $query->where('c.assigned_user_id', $actorId);
        }

        return $query->orderByDesc('t.created_at')->limit(100)->get([
            't.id', 't.conversation_id', 't.outcome', 't.outcome_hash', 't.created_at', 'c.version',
            'contact.name as contact_name', 'channel.type as channel_type', 'input.text as input_text',
        ])->map(function ($row): ?array {
            $outcome = json_decode($row->outcome ?: '', true);
            if (! is_array($outcome) || ! is_string($row->outcome_hash) || ! hash_equals($row->outcome_hash, CanonicalJson::hash($outcome))
                || ($outcome['status'] ?? null) !== 'suggested' || ($outcome['turnId'] ?? null) !== $row->id
                || ($outcome['conversationId'] ?? null) !== $row->conversation_id || (int) ($outcome['version'] ?? -1) !== (int) $row->version
                || ! is_string($outcome['messages'][0]['text'] ?? null) || ($outcome['resolved'] ?? null) !== null) {
                return null;
            }

            return ['id' => $row->id, 'conversation_id' => $row->conversation_id,
                'name' => $row->contact_name ?: 'Гость · '.substr($row->conversation_id, -6),
                'channel' => $row->channel_type ?: 'Не указан', 'created_at' => $row->created_at,
                'input' => $row->input_text, 'suggestion' => $outcome['messages'][0]['text']];
        })->filter()->values()->all();
    }

    /** Re-checks a single ready suggestion right before it is sent; returns its outcome or null if stale or invalid. Locks the turn and conversation rows. Caller must check dialog visibility separately. */
    public function validate(TenantContext $tenant, string $conversationId, string $turnId): ?array
    {
        $row = DB::table('seller_turns as t')
            ->join('conversations as c', function ($join): void {
                $join->on('c.id', '=', 't.conversation_id')->on('c.brand_id', '=', 't.brand_id')->on('c.organization_id', '=', 't.organization_id');
            })->where('t.organization_id', $tenant->organizationId)->where('t.brand_id', $tenant->brandId)
            ->where('t.id', $turnId)->where('t.conversation_id', $conversationId)
            ->where('t.status', 'suggested')->where('c.mode', 'suggest')
            ->whereColumn('t.cutoff_sequence', 'c.history_sequence')->whereRaw('c.history_revision = t.base_history_revision + 1')
            ->lockForUpdate()->first(['t.id', 't.outcome', 't.outcome_hash', 'c.version']);
        if (! $row) {
            return null;
        }
        $outcome = json_decode($row->outcome ?: '', true);
        if (! is_array($outcome) || ! is_string($row->outcome_hash) || ! hash_equals($row->outcome_hash, CanonicalJson::hash($outcome))
            || ($outcome['status'] ?? null) !== 'suggested' || ($outcome['turnId'] ?? null) !== $row->id
            || ($outcome['conversationId'] ?? null) !== $conversationId || (int) ($outcome['version'] ?? -1) !== (int) $row->version
            || ! is_string($outcome['messages'][0]['text'] ?? null) || ($outcome['resolved'] ?? null) !== null) {
            return null;
        }

        return $outcome;
    }
}
