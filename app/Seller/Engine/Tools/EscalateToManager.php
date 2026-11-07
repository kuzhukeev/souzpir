<?php

namespace App\Seller\Engine\Tools;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\ToolArguments;
use App\Seller\Tasks\NotifyDialogEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called only after the protected gateway has validated the tool call: hands the dialog to a human via the brand's existing fake-only cabinet notification. */
final class EscalateToManager
{
    public function run(TenantContext $tenant, TurnClaim $claim, string $responderId, string $toolId, array $arguments): array
    {
        if ($tenant->organizationId !== $claim->organizationId || $tenant->brandId !== $claim->brandId) {
            return ['status' => 'unavailable'];
        }
        if (! (new ToolArguments)->check('escalate', $arguments)['valid']) {
            return ['status' => 'invalid_arguments'];
        }

        return app(PostgresTransactionalIdempotency::class)->execute($claim->organizationId, $claim->brandId, 'seller.fake_escalate_handoff',
            $responderId.':'.$toolId, hash('sha256', json_encode(['turn' => $claim->turnId, 'responder' => $responderId, 'tool' => $toolId],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            fn (): array => $this->handOff($tenant, $claim, $arguments));
    }

    private function handOff(TenantContext $tenant, TurnClaim $claim, array $arguments): array
    {
        $reason = trim((string) ($arguments['reason'] ?? ''));
        $summary = trim((string) ($arguments['summary'] ?? ''));
        $taskId = (string) Str::ulid();
        DB::table('seller_tasks')->insert(['id' => $taskId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $claim->conversationId, 'type' => 'handoff',
            'payload' => json_encode(['turn_id' => $claim->turnId, 'reason' => $reason,
                'text' => $summary !== '' ? $summary : 'Диалог передан менеджеру.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'new', 'priority' => 'urgent', 'created_at' => now(), 'updated_at' => now()]);
        NotifyDialogEvent::dispatch($tenant->organizationId, $tenant->brandId, $claim->conversationId,
            'seller.conversation.handed_off', ['reason' => 'bot_escalate', 'task_id' => $taskId])->afterCommit();

        return ['status' => 'completed'];
    }
}
