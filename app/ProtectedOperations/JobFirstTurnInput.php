<?php

namespace App\ProtectedOperations;

use App\Seller\Conversations\Data\TurnCommand;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Resolve the first inbound message from the explicitly scoped job context. */
final class JobFirstTurnInput
{
    public function resolve(TenantContext $tenant, string $conversationId, string $messageId, string $idempotencyKey): TurnCommand
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || $tenant->source !== 'job'
            || ! $tenant->organizationId || ! $tenant->brandId
            || ! Str::isUlid($conversationId) || ! Str::isUlid($messageId)
            || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new LogicException('Invalid job first-turn input.');
        }
        $organization = DB::table('organizations')->where('id', $tenant->organizationId)->where('status', 'active')->exists();
        $brand = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->where('status', 'active')->exists();
        $module = DB::table('brand_modules')->where(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'module' => 'seller', 'enabled' => true])->exists();
        $conversation = DB::table('conversations')->where(['id' => $conversationId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId])->first(['version', 'mode']);
        $message = DB::table('messages')->where(['id' => $messageId, 'conversation_id' => $conversationId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'role' => 'client'])->first(['text', 'external_event_id']);
        if (! $organization || ! $brand || ! $module || ! $conversation || (int) $conversation->version !== 0 || $conversation->mode !== 'bot' || ! $message) {
            throw new LogicException('Job first-turn input unavailable.');
        }

        return new TurnCommand($conversationId, $messageId, $message->text, $message->external_event_id, $idempotencyKey, 0);
    }
}
