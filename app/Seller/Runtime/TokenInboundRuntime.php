<?php

namespace App\Seller\Runtime;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerFollowup\FollowupFinalizer;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Channels\Api\ApiSourceReceipt;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\BrandAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Token-scoped fake-only API entry to the protected seller follow-up runtime. */
final class TokenInboundRuntime
{
    public function turn(Request $request, TenantContext $tenant, string $conversationId, string $text, string $key, ?string $source = null): array
    {
        $token = $this->authorize($request, $tenant, 'turn');
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        if (! DB::table('seller_api_lead_requests')->where($scope)->where('conversation_id', $conversationId)->exists()) {
            abort(404);
        }
        $input = ['conversation_id' => $conversationId, 'text' => $text, 'token_id' => (string) $token->id];
        if ($source !== null) {
            if (! in_array($source, ['site', 'crm'], true)) {
                throw new LogicException('Invalid API source.');
            }
            $input['source'] = $source;
        }
        $payload = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $result = app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.api.turn', $key, $payload,
            function () use ($tenant, $scope, $conversationId, $text, $key): array {
                $conversation = DB::table('conversations')->where($scope)->where('id', $conversationId)->lockForUpdate()->first();
                if (! $conversation || ! in_array($conversation->mode, ['bot', 'manager', 'suggest'], true) || in_array($conversation->stage, ['done', 'lost', 'spam'], true)
                    || ($conversation->stage === 'handoff' && $conversation->mode !== 'manager')) {
                    throw new LogicException('API turn unavailable.');
                }
                $schedule = app(BrandRuntimeSchedule::class);
                $settings = $schedule->settings($tenant);
                $scheduleMode = $schedule->mode($tenant, $settings, CarbonImmutable::now());
                $mode = $conversation->mode;
                if ($mode === 'manager') {
                    $schedule->assignWaiting($tenant, $conversationId, $settings);
                } elseif ($scheduleMode === 'suggest' && $mode === 'bot') {
                    DB::table('conversations')->where($scope)->where('id', $conversationId)->update(['mode' => 'suggest', 'updated_at' => now()]);
                    $mode = 'suggest';
                }
                if ($mode === 'bot' && $scheduleMode !== 'off' && (int) $conversation->version < 1) {
                    throw new LogicException('API first turn unavailable.');
                }
                $message = (string) Str::ulid();
                DB::table('messages')->insert(['id' => $message, ...$scope, 'conversation_id' => $conversationId, 'role' => 'client', 'text' => $text,
                    'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                $turnKey = 'api:'.hash('sha256', $key);
                $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversationId, $message, $text, null, $turnKey, (int) $conversation->version));
                if ($claim->status !== 'claimed') {
                    throw new LogicException('API turn unavailable.');
                }
                if ($mode === 'manager' || $scheduleMode === 'off') {
                    return $this->suppress($tenant, $claim, $mode);
                }
                $outcome = app(FollowupFinalizer::class)->finish($tenant, $claim, $mode);
                if ($mode === 'suggest') {
                    return ['conversation_id' => $conversationId, 'status' => 'suggested', 'version' => $outcome['result']['version']];
                }
                $replyId = $outcome['result']['message_id'] ?? null;
                $reply = is_string($replyId) ? DB::table('messages')->where($scope)->where('conversation_id', $conversationId)->where('id', $replyId)->value('text') : null;
                if (! is_string($reply)) {
                    throw new LogicException('API answer unavailable.');
                }
                $receipt = app(FakeChannelDelivery::class)->send(new DeliveryCommand($tenant, $conversationId, $replyId, 'fake', 'api-turn:'.$claim->turnId));
                if ($receipt->status !== 'fake_sent' || $receipt->messageId !== $replyId) {
                    throw new LogicException('Fake delivery unavailable.');
                }

                return ['conversation_id' => $conversationId, 'message_id' => $replyId, 'reply' => $reply, 'version' => $outcome['result']['version']];
            });
        if ($source !== null) {
            $digest = hash_hmac('sha256', "protected-operation-key-v1\0".$key, (string) config('app.key'));
            $receiptId = DB::table('protected_operation_receipts')->where($scope)->where('operation', 'seller.api.turn')
                ->where('key_digest', $digest)->value('id');
            if (! is_string($receiptId)) {
                throw new LogicException('API turn receipt unavailable.');
            }
            app(ApiSourceReceipt::class)->save($tenant->organizationId, $tenant->brandId, $receiptId, $source);
        }

        return $result;
    }

    private function authorize(Request $request, TenantContext $tenant, string $ability): BrandAccessToken
    {
        $token = $request->attributes->get('tenant.token');
        if ($tenant->source !== 'token' || ! $tenant->organizationId || ! $tenant->brandId || ! $token instanceof BrandAccessToken
            || $token->organization_id !== $tenant->organizationId || $token->brand_id !== $tenant->brandId
            || ! is_array($token->abilities) || ! in_array($ability, $token->abilities, true)
            || config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() < 1
            || ! DB::table('personal_access_tokens')->where('id', $token->id)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('token', $token->token)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()
            || ! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            throw new LogicException('API runtime unavailable.');
        }

        return $token;
    }

    private function suppress(TenantContext $tenant, TurnClaim $claim, string $mode): array
    {
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $conversation = DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->lockForUpdate()->first();
        if (! $conversation || $conversation->mode !== $mode) {
            throw new LogicException('API turn unavailable.');
        }
        $state = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new LogicException('API turn unavailable.');
        }
        $outcome = new TurnOutcome($claim->turnId, $claim->conversationId, 'suppressed', $claim->baseVersion + 1, null, null, null, [], $state);
        $data = $outcome->toArray();
        DB::table('seller_turns')->where($scope)->where('id', $claim->turnId)->where('status', 'processing')->update([
            'status' => 'suppressed', 'outcome' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'outcome_hash' => CanonicalJson::hash($data), 'updated_at' => now(),
        ]);
        DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->update(['version' => $claim->baseVersion + 1, 'updated_at' => now()]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.suppressed', 'seller_turn', $claim->turnId));

        return ['conversation_id' => $claim->conversationId, 'status' => 'suppressed', 'version' => $claim->baseVersion + 1];
    }
}
