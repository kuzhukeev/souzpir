<?php

namespace App\Seller\Runtime;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerFollowup\FollowupFinalizer;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Seller\Settings\Limits\SellerModuleAccess;
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
    public function turn(Request $request, TenantContext $tenant, string $conversationId, string $text, string $key): array
    {
        $token = $this->authorize($request, $tenant, 'turn');
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        if (! DB::table('seller_api_lead_requests')->where($scope)->where('conversation_id', $conversationId)->exists()) {
            abort(404);
        }
        $this->assertAutonomous($tenant);
        $payload = json_encode(['conversation_id' => $conversationId, 'text' => $text, 'token_id' => (string) $token->id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.api.turn', $key, $payload,
            function () use ($tenant, $scope, $conversationId, $text, $key): array {
                $conversation = DB::table('conversations')->where($scope)->where('id', $conversationId)->lockForUpdate()->first();
                if (! $conversation || $conversation->mode !== 'bot' || (int) $conversation->version < 1 || in_array($conversation->stage, ['done', 'lost', 'spam', 'handoff'], true)) {
                    throw new LogicException('API turn unavailable.');
                }
                $message = (string) Str::ulid();
                DB::table('messages')->insert(['id' => $message, ...$scope, 'conversation_id' => $conversationId, 'role' => 'client', 'text' => $text,
                    'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                $turnKey = 'api:'.hash('sha256', $key);
                $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversationId, $message, $text, null, $turnKey, (int) $conversation->version));
                if ($claim->status !== 'claimed') {
                    throw new LogicException('API turn unavailable.');
                }
                $outcome = app(FollowupFinalizer::class)->finish($tenant, $claim, 'bot');
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

    private function assertAutonomous(TenantContext $tenant): void
    {
        $schedule = app(BrandRuntimeSchedule::class);
        if ($schedule->mode($tenant, $schedule->settings($tenant), CarbonImmutable::now()) !== 'autonomous') {
            throw new LogicException('API runtime unavailable.');
        }
    }
}
