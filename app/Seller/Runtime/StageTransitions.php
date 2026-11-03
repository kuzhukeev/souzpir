<?php

namespace App\Seller\Runtime;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StageTransitions
{
    public function record(TenantContext $tenant, string $conversationId, string $before, string $after): void
    {
        if ($before === $after) {
            return;
        }
        DB::table('seller_stage_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
            'brand_id' => $tenant->brandId, 'conversation_id' => $conversationId,
            'from_stage' => $before, 'to_stage' => $after, 'occurred_at' => now()]);
    }
}
