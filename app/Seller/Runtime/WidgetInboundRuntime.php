<?php

namespace App\Seller\Runtime;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Fake-only widget turn: no AI stage exists for a public visitor yet, so every turn hands off to a manager. */
final class WidgetInboundRuntime
{
    public function turn(object $widget, object $session, string $text, string $idempotencyKey): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql') {
            return ['status' => 'unavailable'];
        }
        $tenant = new TenantContext($widget->organization_id, $widget->brand_id, source: 'public');
        if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            return ['status' => 'unavailable'];
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $payload = json_encode(['session' => $session->id, 'text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.widget.turn', $idempotencyKey, $payload,
                function () use ($tenant, $scope, $widget, $session, $text, $idempotencyKey): array {
                    $sessionRow = DB::table('widget_sessions')->where('id', $session->id)->lockForUpdate()->first();
                    if (! $sessionRow || $sessionRow->expires_at <= now()) {
                        throw new LogicException('Widget session unavailable.');
                    }
                    $conversationId = $sessionRow->conversation_id ?? $this->openConversation($scope, $widget, $sessionRow);
                    $conversation = DB::table('conversations')->where($scope)->where('id', $conversationId)->lockForUpdate()->first();
                    if (! $conversation || $conversation->mode !== 'manager') {
                        throw new LogicException('Widget turn unavailable.');
                    }
                    $externalId = hash('sha256', 'widget-session:'.$sessionRow->id.':'.$idempotencyKey);
                    $message = app(InboundRecorder::class)->ingest($tenant, $conversation->channel_account_id, $externalId, ['text' => $text], $conversationId, $text);
                    $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand(
                        $conversationId, $message->id, $text, $message->external_event_id, 'widget:'.hash('sha256', $idempotencyKey), (int) $conversation->version
                    ));
                    if ($claim->status !== 'claimed') {
                        throw new LogicException('Widget turn unavailable.');
                    }

                    return $this->suppress($tenant, $scope, $claim);
                });
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    private function openConversation(array $scope, object $widget, object $sessionRow): string
    {
        $contactId = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contactId, ...$scope, 'name' => null, 'email' => null, 'phone' => null, 'created_at' => now(), 'updated_at' => now()]);
        $channel = DB::table('channel_accounts')->where($scope)->where('type', 'widget')->where('settings->widget_id', $widget->id)->first();
        $channelId = $channel->id ?? (string) Str::ulid();
        if (! $channel) {
            DB::table('channel_accounts')->insert(['id' => $channelId, ...$scope, 'type' => 'widget', 'name' => 'Сайт', 'status' => 'active',
                'default_mode' => 'manager', 'settings' => json_encode(['widget_id' => $widget->id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        $conversationId = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversationId, ...$scope, 'channel_account_id' => $channelId, 'contact_id' => $contactId,
            'mode' => 'manager', 'state' => '{}', 'last_client_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('widget_sessions')->where('id', $sessionRow->id)->update(['conversation_id' => $conversationId, 'contact_id' => $contactId, 'updated_at' => now()]);

        return $conversationId;
    }

    private function suppress(TenantContext $tenant, array $scope, TurnClaim $claim): array
    {
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $conversation = DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->lockForUpdate()->first();
        if (! $conversation || $conversation->mode !== 'manager') {
            throw new LogicException('Widget turn unavailable.');
        }
        $state = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new LogicException('Widget turn unavailable.');
        }
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, 'suppressed', $claim->baseVersion + 1, null, null, null, [], $state);
        $data = $outcome->toArray();
        DB::table('seller_turns')->where($scope)->where('id', $claim->turnId)->where('status', 'processing')->update([
            'status' => 'suppressed', 'outcome' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'outcome_hash' => CanonicalJson::hash($data), 'updated_at' => now(),
        ]);
        DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->update(['version' => $claim->baseVersion + 1, 'updated_at' => now()]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.suppressed', 'seller_turn', $claim->turnId));

        return ['status' => 'suppressed', 'version' => $claim->baseVersion + 1];
    }
}
