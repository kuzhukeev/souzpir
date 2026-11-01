<?php

namespace App\Seller\Engine;

use App\Ai\Gateway;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\ProtectedOperations\FirstTurnProtectedClaim;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Data\TurnClaim;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Internal fake-only effect; the caller must already own the protected first turn. */
final class CreatePromise
{
    /** @return array{task_id:string} */
    public function execute(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim,
        #[SensitiveParameter] string $responderId, int $toolIndex, #[SensitiveParameter] string $idempotencyKey): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql'
                || DB::connection()->transactionLevel() !== 0 || ! Str::isUlid($responderId)
                || $toolIndex < 0 || $idempotencyKey === '' || strlen($idempotencyKey) > 512) {
                throw new LogicException;
            }

            return DB::transaction(function () use ($http, $claim, $responderId, $toolIndex, $idempotencyKey): array {
                $tenant = app(TenantContext::class);
                $existing = DB::table('seller_tasks')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('conversation_id', $claim->conversationId)
                    ->where('type', 'promise')->where('payload->turn_id', $claim->turnId)
                    ->lockForUpdate()->first();
                if ($existing) {
                    if (app(FirstTurnProtectedClaim::class)->assertOriginLocked($http, $claim) !== 'processing') {
                        throw new LogicException;
                    }
                    $payload = json_decode($existing->payload, true, 512, JSON_THROW_ON_ERROR);
                    if (($payload['responder_id'] ?? null) !== $responderId || ($payload['tool_index'] ?? null) !== $toolIndex) {
                        throw new IdempotencyConflict;
                    }
                    $body = CanonicalJson::hash(['turn' => $claim->turnId, 'responder' => $responderId,
                        'index' => $toolIndex, 'tool_id' => $payload['tool_id'], 'text' => $payload['text']]);

                    return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                        'seller.create_promise', $idempotencyKey, $body, static function (): array {
                            throw new LogicException;
                        });
                }
                $raw = app(Gateway::class)->readProtectedRawToolCallLocked($http, $claim, $responderId, $toolIndex);
                $arguments = json_decode($raw['arguments_json'], true, 22, JSON_THROW_ON_ERROR);
                if ($raw['name'] !== 'create_promise' || ! is_array($arguments)
                    || array_keys($arguments) !== ['text'] || ! is_string($arguments['text'])) {
                    throw new LogicException;
                }
                $text = trim($arguments['text']);
                if ($text === '' || mb_strlen($text) > 1000) {
                    throw new LogicException;
                }
                $body = CanonicalJson::hash(['turn' => $claim->turnId, 'responder' => $responderId,
                    'index' => $toolIndex, 'tool_id' => $raw['id'], 'text' => $text]);

                return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                    'seller.create_promise', $idempotencyKey, $body, function () use ($tenant, $claim, $responderId, $toolIndex, $raw, $text): array {
                        $conversation = DB::table('conversations')->where('id', $claim->conversationId)
                            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                            ->lockForUpdate()->first();
                        if (! $conversation) {
                            throw new LogicException;
                        }
                        $state = new LeadState(json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR));
                        $state->addPromise($text);
                        $taskId = (string) Str::ulid();
                        DB::table('seller_tasks')->insert(['id' => $taskId,
                            'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                            'conversation_id' => $claim->conversationId, 'type' => 'promise',
                            'payload' => json_encode(['text' => $text, 'turn_id' => $claim->turnId,
                                'responder_id' => $responderId, 'tool_index' => $toolIndex, 'tool_id' => $raw['id']],
                                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'status' => 'new', 'priority' => 'normal', 'created_at' => now(), 'updated_at' => now()]);
                        DB::table('conversations')->where('id', $conversation->id)
                            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                            ->update(['state' => json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.promise.created', 'seller_task', $taskId,
                            after: ['turn_id' => $claim->turnId, 'conversation_id' => $claim->conversationId]));

                        return ['task_id' => $taskId];
                    });
            });
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Promise key was used with different content.');
        } catch (Throwable) {
            throw new LogicException('Protected promise unavailable.');
        }
    }
}
