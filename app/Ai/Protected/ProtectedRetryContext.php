<?php

namespace App\Ai\Protected;

use App\Contracts\Ai\AiContext;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Use a fresh attempt only when the preceding fake call is confirmed failed. */
final class ProtectedRetryContext
{
    public function make(TenantContext $tenant, string $conversation, string $purpose, string $base): AiContext
    {
        $key = $base;
        $attempt = 1;
        $parent = null;
        while (true) {
            $digest = hash_hmac('sha256', $key, (string) config('app.key'));
            $call = DB::table('ai_calls')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('idempotency_key_hash', $digest)->first(['id', 'status']);
            if (! $call || $call->status !== 'failed') {
                break;
            }
            $parent = $call->id;
            $attempt++;
            $key = $base.'-retry-'.$attempt;
        }

        return new AiContext($tenant->organizationId, $tenant->brandId, $purpose, 'seller', 'conversation',
            $conversation, $key, ['conversation_content', 'client_profile', 'knowledge_internal'], $attempt, $parent);
    }
}
