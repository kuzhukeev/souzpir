<?php

namespace App\Seller\Runtime;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerFollowup\FollowupCoordinator;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Runtime\Contracts\ChannelDelivery;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Widget turn: in fake mode a bot-mode conversation drives the same protected pipeline as other channels; in live mode every turn is suppressed to a manager/suggest handoff (the live draft is queued separately via the LV-01 message listener) — the widget never autonomously answers a visitor with AI. Off-hours always hand off to a manager. */
final class WidgetInboundRuntime
{
    public function turn(Request $http, object $widget, object $session, string $text, string $idempotencyKey, string $page = 'home'): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return ['status' => 'unavailable'];
        }
        $driver = config('runtime.ai_driver', 'fake');
        $tenant = new TenantContext($widget->organization_id, $widget->brand_id, source: 'public');
        try {
            return app(TenantContextRunner::class)->run($tenant, fn () => $this->turnWithinTenant($http, $tenant, $widget, $session, $text, $idempotencyKey, $page, $driver));
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    private function turnWithinTenant(Request $http, TenantContext $tenant, object $widget, object $session, string $text, string $idempotencyKey, string $page, string $driver): array
    {
        if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            return ['status' => 'unavailable'];
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $payload = json_encode(['session' => $session->id, 'text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            $claimed = app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.widget.turn.claim', $idempotencyKey, $payload,
                function () use ($tenant, $scope, $widget, $session, $text, $idempotencyKey, $driver): array {
                    $sessionRow = DB::table('widget_sessions')->where('id', $session->id)->lockForUpdate()->first();
                    if (! $sessionRow || $sessionRow->expires_at <= now()) {
                        throw new LogicException('Widget session unavailable.');
                    }
                    $conversationId = $sessionRow->conversation_id ?? $this->openConversation($tenant, $scope, $widget, $sessionRow, $driver);
                    $conversation = DB::table('conversations')->where($scope)->where('id', $conversationId)->lockForUpdate()->first();
                    if (! $conversation || ! in_array($conversation->mode, ['manager', 'bot', 'suggest'], true)) {
                        throw new LogicException('Widget turn unavailable.');
                    }
                    $externalId = hash('sha256', 'widget-session:'.$sessionRow->id.':'.$idempotencyKey);
                    $message = app(InboundRecorder::class)->ingest($tenant, $conversation->channel_account_id, $externalId, ['text' => $text], $conversationId, $text);
                    $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand(
                        $conversationId, $message->id, $text, $message->external_event_id, 'widget:'.hash('sha256', $idempotencyKey), (int) $conversation->version
                    ));
                    $limitReached = $this->messageLimitReached($widget, $scope, $conversationId);
                    if ($conversation->mode === 'manager' || $driver !== 'fake' || $limitReached) {
                        if ($claim->status !== 'claimed') {
                            throw new LogicException('Widget turn unavailable.');
                        }

                        return ['mode' => 'manager', 'result' => $this->suppress($tenant, $scope, $claim, [$conversation->mode], $limitReached)];
                    }
                    if ($claim->status !== 'claimed' || ! is_string($claim->token())) {
                        throw new LogicException('Widget turn unavailable.');
                    }

                    return ['mode' => $conversation->mode, 'conversation' => $conversationId,
                        'turn' => $claim->turnId, 'token' => $claim->token(), 'version' => $claim->baseVersion];
                });
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
        if ($claimed['mode'] === 'manager') {
            return $claimed['result'];
        }

        return $this->completeBotTurn($http, $tenant, $scope, $claimed, $page);
    }

    /** Runs the bot pipeline for a freshly claimed turn, or replays the outcome of an already-finished one on retry. */
    private function completeBotTurn(Request $http, TenantContext $tenant, array $scope, array $claimed, string $page): array
    {
        try {
            $turn = DB::table('seller_turns')->where($scope)->where('id', $claimed['turn'])->first();
            if ($turn && $turn->status === 'failed') {
                return ['status' => 'suppressed', 'version' => $claimed['version'] + 1];
            }
            if (! $turn || $turn->status === 'processing') {
                try {
                    $claim = new TurnClaim('claimed', $claimed['turn'], $claimed['conversation'], $tenant->organizationId, $tenant->brandId, $claimed['version'], $claimed['token']);
                    app(FollowupCoordinator::class)->orchestrateTurn($http, $tenant, $claim, $claimed['mode'], $page);
                } catch (Throwable) {
                    return $this->handoffToManager($tenant, $scope, $claimed);
                }
                $turn = DB::table('seller_turns')->where($scope)->where('id', $claimed['turn'])->first();
            }
            if (! $turn || ! in_array($turn->status, ['completed', 'suggested'], true)) {
                return $this->handoffToManager($tenant, $scope, $claimed);
            }
            $outcome = json_decode($turn->outcome, true, 512, JSON_THROW_ON_ERROR);
            if ($turn->status === 'suggested') {
                return ['status' => 'suggested', 'version' => $outcome['version'] ?? null];
            }
            $messageId = $outcome['assistantMessageId'] ?? null;
            $reply = is_string($messageId) ? DB::table('messages')->where($scope)->where('id', $messageId)
                ->where('conversation_id', $claimed['conversation'])->value('text') : null;
            if (! is_string($messageId) || ! is_string($reply)) {
                throw new LogicException('Widget turn unavailable.');
            }
            if (! app()->bound(ChannelDelivery::class)) {
                app()->singleton(ChannelDelivery::class, FakeChannelDelivery::class);
            }
            $receipt = app(ChannelDelivery::class)->send(new DeliveryCommand($tenant, $claimed['conversation'], $messageId, 'fake', 'widget-turn:'.$claimed['turn']));
            if ($receipt->status !== 'fake_sent' || $receipt->messageId !== $messageId) {
                throw new LogicException('Widget turn unavailable.');
            }

            return ['status' => 'completed', 'reply' => $reply, 'version' => $outcome['version'] ?? null];
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /** The bot pipeline could not answer (e.g. no knowledge base on a fresh brand); hand the already-saved message off to a manager instead of failing the request. */
    private function handoffToManager(TenantContext $tenant, array $scope, array $claimed): array
    {
        return DB::transaction(function () use ($tenant, $scope, $claimed): array {
            $turn = DB::table('seller_turns')->where($scope)->where('id', $claimed['turn'])->lockForUpdate()->first();
            if ($turn && $turn->status === 'failed') {
                return ['status' => 'suppressed', 'version' => $claimed['version'] + 1];
            }
            $conversation = DB::table('conversations')->where($scope)->where('id', $claimed['conversation'])->lockForUpdate()->first();
            if (! $turn || $turn->status !== 'processing' || ! $conversation || (int) $conversation->version !== $claimed['version']) {
                throw new LogicException('Widget turn unavailable.');
            }
            $state = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                throw new LogicException('Widget turn unavailable.');
            }
            $outcome = new TurnOutcome($claimed['turn'], $claimed['conversation'], 'failed', $claimed['version'] + 1, null, null, null, [], $state);
            $data = $outcome->toArray();
            DB::table('seller_turns')->where($scope)->where('id', $claimed['turn'])->where('status', 'processing')->update([
                'status' => 'failed', 'outcome' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'outcome_hash' => CanonicalJson::hash($data), 'updated_at' => now(),
            ]);
            DB::table('conversations')->where($scope)->where('id', $claimed['conversation'])
                ->update(['mode' => 'manager', 'version' => $claimed['version'] + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.failed', 'seller_turn', $claimed['turn']));

            return ['status' => 'suppressed', 'version' => $claimed['version'] + 1];
        });
    }

    private function openConversation(TenantContext $tenant, array $scope, object $widget, object $sessionRow, string $driver): string
    {
        $contactId = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contactId, ...$scope, 'name' => null, 'email' => null, 'phone' => null, 'created_at' => now(), 'updated_at' => now()]);
        $channel = DB::table('channel_accounts')->where($scope)->where('type', 'widget')->where('settings->widget_id', $widget->id)->first();
        $channelId = $channel->id ?? (string) Str::ulid();
        $defaultMode = $channel->default_mode ?? ($driver === 'fake' ? 'bot' : 'manager');
        if (! $channel) {
            DB::table('channel_accounts')->insert(['id' => $channelId, ...$scope, 'type' => 'widget', 'name' => 'Сайт', 'status' => 'active',
                'default_mode' => $defaultMode, 'settings' => json_encode(['widget_id' => $widget->id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        $schedule = app(BrandRuntimeSchedule::class);
        $off = $schedule->mode($tenant, $schedule->settings($tenant), CarbonImmutable::now()) === 'off';
        $mode = $off ? 'manager' : ($driver === 'fake' ? 'bot' : $defaultMode);
        $conversationId = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversationId, ...$scope, 'channel_account_id' => $channelId, 'contact_id' => $contactId,
            'mode' => $mode, 'state' => '{}', 'last_client_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('widget_sessions')->where('id', $sessionRow->id)->update(['conversation_id' => $conversationId, 'contact_id' => $contactId, 'updated_at' => now()]);

        return $conversationId;
    }

    /** Antispam limit (D-9.4, widget_settings.behavior.max_messages): counts visitor messages already stored for the
     * conversation, including the one just ingested for this turn. */
    private function messageLimitReached(object $widget, array $scope, string $conversationId): bool
    {
        $behavior = is_string($widget->behavior ?? null) ? json_decode($widget->behavior, true) : null;
        $maxMessages = is_array($behavior) && is_int($behavior['max_messages'] ?? null) ? $behavior['max_messages'] : null;
        if ($maxMessages === null) {
            return false;
        }

        return DB::table('messages')->where($scope)->where('conversation_id', $conversationId)->where('role', 'client')->count() > $maxMessages;
    }

    private function suppress(TenantContext $tenant, array $scope, TurnClaim $claim, array $allowedModes, bool $limitReached = false): array
    {
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $conversation = DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->lockForUpdate()->first();
        if (! $conversation || ! in_array($conversation->mode, $allowedModes, true)) {
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

        $result = ['status' => 'suppressed', 'version' => $claim->baseVersion + 1];
        if ($limitReached) {
            $result['notice'] = 'Вы отправили максимум сообщений в этом чате. Дальше вам ответит менеджер.';
        }

        return $result;
    }
}
