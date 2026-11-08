<?php

namespace App\Seller\Engine\Tools;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Append-only counter of bot action calls: organization_id, brand_id, dialog_id,
 * tool, status, created_at only. Never stores arguments, response text or contacts.
 */
final class ToolCallJournal
{
    public function record(TenantContext $tenant, string $tool, string $status, ?string $dialogId = null, ?string $turnId = null): void
    {
        if (! $tenant->organizationId || ! $tenant->brandId) {
            return;
        }
        try {
            DB::table('seller_tool_call_journal')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'organization_id' => $tenant->organizationId,
                'brand_id' => $tenant->brandId,
                'dialog_id' => $dialogId,
                'turn_id' => $turnId,
                'tool' => $tool,
                'status' => $status,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // The journal is an auxiliary counter; a write failure must not affect the tool result.
        }
    }

    public function countSince(TenantContext $tenant, string $tool, Carbon $since): int
    {
        return (int) DB::table('seller_tool_call_journal')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('tool', $tool)->where('created_at', '>=', $since)->count();
    }

    /** Earliest moment the journal has any row for the brand, or null when it never recorded one. */
    public function recordingSince(TenantContext $tenant): ?Carbon
    {
        $value = DB::table('seller_tool_call_journal')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->min('created_at');

        return $value ? Carbon::parse($value) : null;
    }
}
