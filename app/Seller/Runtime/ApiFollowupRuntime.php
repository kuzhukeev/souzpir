<?php

namespace App\Seller\Runtime;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerFollowup\FollowupCoordinator;
use App\ProtectedOperations\SellerFollowup\FollowupFinalizer;
use App\Seller\Channels\Api\ApiSourceReceipt;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\TokenTenantAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Fake-only API turn with transaction boundaries around protected AI calls. */
final class ApiFollowupRuntime
{
    public function turn(Request $http, TenantContext $tenant, string $conversationId, string $text, string $key, ?string $source): array
    {
        if (DB::transactionLevel() !== 0 || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('API follow-up unavailable.');
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $prepared = DB::transaction(function () use ($http, $tenant, $conversationId, $text, $key, $source, $scope): array {
            $token = app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'turn');
            if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)
                || ! DB::table('seller_api_lead_requests')->where($scope)->where('conversation_id', $conversationId)->exists()) {
                abort(404);
            }
            $data = ['conversation_id' => $conversationId, 'text' => $text, 'token_id' => (string) $token->id];
            if ($source !== null) {
                $data['source'] = $source;
            }
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $digest = hash_hmac('sha256', "protected-operation-key-v1\0".$key, (string) config('app.key'));
            if (DB::table('protected_operation_receipts')->where($scope)->where('operation', 'seller.api.turn')->where('key_digest', $digest)->exists()) {
                return ['legacy' => true];
            }
            $conversation = DB::table('conversations')->where($scope)->where('id', $conversationId)->lockForUpdate()->first();
            if (! $conversation || ! in_array($conversation->mode, ['bot', 'manager', 'suggest'], true)
                || in_array($conversation->stage, ['done', 'lost', 'spam'], true)
                || ($conversation->stage === 'handoff' && $conversation->mode !== 'manager')) {
                throw new LogicException('API follow-up unavailable.');
            }
            $schedule = app(BrandRuntimeSchedule::class);
            $mode = $schedule->mode($tenant, $schedule->settings($tenant), CarbonImmutable::now());
            if ($conversation->mode === 'manager' || $mode === 'off') {
                return ['legacy' => true];
            }
            if ($conversation->mode === 'bot' && $mode === 'suggest') {
                DB::table('conversations')->where($scope)->where('id', $conversationId)->update(['mode' => 'suggest', 'updated_at' => now()]);
                $conversation->mode = 'suggest';
            }
            if ($conversation->mode === 'bot' && (int) $conversation->version < 1) {
                throw new LogicException('API first turn unavailable.');
            }
            $receipt = app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                'seller.api.turn.claim', $key, $payload, function () use ($tenant, $scope, $conversationId, $text, $key, $conversation): array {
                    $message = (string) Str::ulid();
                    DB::table('messages')->insert(['id' => $message, ...$scope, 'conversation_id' => $conversationId, 'role' => 'client',
                        'text' => $text, 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                    $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand(
                        $conversationId, $message, $text, null, 'api:'.hash('sha256', $key), (int) $conversation->version));
                    if ($claim->status !== 'claimed' || ! is_string($claim->token())) {
                        throw new LogicException('API follow-up unavailable.');
                    }

                    return ['turn' => $claim->turnId, 'token' => $claim->token(), 'version' => $claim->baseVersion, 'mode' => $conversation->mode];
                });

            return ['legacy' => false, 'receipt' => $receipt, 'payload' => $payload];
        });
        if ($prepared['legacy']) {
            return DB::transaction(fn () => app(TokenInboundRuntime::class)->turn($http, $tenant, $conversationId, $text, $key, $source));
        }
        $receipt = $prepared['receipt'];
        if (! is_string($receipt['turn'] ?? null) || ! is_string($receipt['token'] ?? null)
            || ! is_int($receipt['version'] ?? null) || ! in_array($receipt['mode'] ?? null, ['bot', 'suggest'], true)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $claim = new TurnClaim('claimed', $receipt['turn'], $conversationId, $tenant->organizationId, $tenant->brandId, $receipt['version'], $receipt['token']);
        [$round, $toolResults, $guardIssues] = app(FollowupCoordinator::class)->prepareFinalRound($http, $tenant, $claim);

        return DB::transaction(function () use ($http, $tenant, $claim, $receipt, $key, $source, $scope, $prepared, $round, $toolResults, $guardIssues): array {
            app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'turn');
            $result = app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                'seller.api.turn', $key, $prepared['payload'], function () use ($http, $tenant, $claim, $receipt, $scope, $round, $toolResults, $guardIssues): array {
                    $outcome = app(FollowupFinalizer::class)->finishProtectedApi($http, $tenant, $claim, $receipt['mode'], $round, $toolResults, $guardIssues);
                    if ($receipt['mode'] === 'suggest') {
                        return ['conversation_id' => $claim->conversationId, 'status' => 'suggested', 'version' => $outcome['result']['version']];
                    }
                    $message = $outcome['result']['message_id'];
                    $reply = DB::table('messages')->where($scope)->where('id', $message)->where('conversation_id', $claim->conversationId)->value('text');
                    $delivery = app(FakeChannelDelivery::class)->send(new DeliveryCommand($tenant, $claim->conversationId, $message, 'fake', 'api-turn:'.$claim->turnId));
                    if (! is_string($reply) || $delivery->status !== 'fake_sent' || $delivery->messageId !== $message) {
                        throw new LogicException('API follow-up unavailable.');
                    }

                    return ['conversation_id' => $claim->conversationId, 'message_id' => $message, 'reply' => $reply, 'version' => $outcome['result']['version']];
                });
            if ($source !== null) {
                $digest = hash_hmac('sha256', "protected-operation-key-v1\0".$key, (string) config('app.key'));
                $id = DB::table('protected_operation_receipts')->where($scope)->where('operation', 'seller.api.turn')->where('key_digest', $digest)->value('id');
                if (! is_string($id)) {
                    throw new LogicException('API receipt unavailable.');
                }
                app(ApiSourceReceipt::class)->save($tenant->organizationId, $tenant->brandId, $id, $source);
            }

            return $result;
        });
    }
}
