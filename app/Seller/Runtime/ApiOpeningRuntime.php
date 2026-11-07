<?php

namespace App\Seller\Runtime;

use App\Ai\CanonicalJson;
use App\Ai\Gateway;
use App\Ai\Protected\ApiOpeningInput;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Seller\Channels\Api\LeadIngressController;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\Policy;
use App\Seller\Engine\ResponderResult;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\TokenTenantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Fake-only opening: create, call outside transaction, recheck and finalize. */
final class ApiOpeningRuntime
{
    public function open(Request $http, TenantContext $tenant, array $input, string $key, string $hash): array
    {
        if (DB::transactionLevel() !== 0 || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('API opening unavailable.');
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        [$lead, $created] = DB::transaction(function () use ($http, $tenant, $input, $key, $hash, $scope): array {
            app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'leads');
            if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
                throw new LogicException('API opening unavailable.');
            }
            $existing = DB::table('seller_api_lead_requests')->where($scope)->where('endpoint', 'leads')
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new IdempotencyConflict;
                }

                return [$existing, false];
            }
            $lead = app(LeadIngressController::class)->createMappedLead($tenant, $input, $key, $hash, 'opening');

            return [DB::table('seller_api_lead_requests')->where('id', $lead['id'])->first(), true];
        });
        if ($lead->status === 'opened') {
            return [$this->readOpened($http, $tenant, $lead->id), false];
        }
        if ($lead->status !== 'opening') {
            throw new LogicException('API opening unavailable.');
        }
        $fingerprint = DB::transaction(function () use ($http, $tenant, $lead): string {
            [$input] = app(ApiOpeningInput::class)->buildLocked($http, $tenant, $lead->id);

            return CanonicalJson::hash($input->toArray());
        });
        $result = app(Gateway::class)->completeApiOpening($http, $tenant, $lead->id);
        $candidate = app(ResponderResult::class)->interpret($result);
        if (($candidate['status'] ?? null) !== 'candidate') {
            throw new LogicException('API opening unavailable.');
        }
        $response = DB::transaction(function () use ($http, $tenant, $lead, $scope, $fingerprint, $candidate): array {
            [$input, , $state, $goal, $knowledge] = app(ApiOpeningInput::class)->buildLocked($http, $tenant, $lead->id);
            if (! hash_equals($fingerprint, CanonicalJson::hash($input->toArray()))) {
                throw new LogicException('API opening changed.');
            }
            $guard = (new DeterministicGuard($knowledge))->check($candidate['text'], $goal, $state);
            $decision = app(GuardDecision::class)->finish($guard, $goal, (bool) config('agent.guard_llm', false));
            if ($decision['status'] !== 'completed' || $decision['verdict'] !== 'ok') {
                throw new LogicException('API opening rejected.');
            }
            if ($goal->slot) {
                $state->markAsked($goal->slot);
                if ($goal->slot === 'budget_range') {
                    $state->inc('budget_asks');
                }
            }
            $policy = new Policy($knowledge);
            $state->setOutcome($policy->computeOutcome($state));
            $state->data['missing'] = $policy->computeMissing($state);
            $message = (string) Str::ulid();
            DB::table('messages')->insert(['id' => $message, ...$scope, 'conversation_id' => $lead->conversation_id,
                'role' => 'agent', 'text' => $candidate['text'], 'meta' => json_encode(['api_opening' => $lead->id, 'goal_kind' => $goal->kind, 'guard' => 'ok'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->where($scope)->where('id', $lead->conversation_id)->update([
                'state' => json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'stage' => $state->stage(),
                'outcome' => $state->outcome(), 'client_type' => $state->clientType(), 'version' => 1, 'updated_at' => now(),
            ]);
            $delivery = app(FakeChannelDelivery::class)->send(new DeliveryCommand($tenant, $lead->conversation_id, $message, 'fake', 'api-opening:'.$lead->id));
            if ($delivery->status !== 'fake_sent' || $delivery->messageId !== $message) {
                throw new LogicException('API opening unavailable.');
            }
            DB::table('seller_api_lead_requests')->where($scope)->where('id', $lead->id)->update(['status' => 'opened', 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.api.opened', 'conversation', $lead->conversation_id));

            return ['contact_id' => $lead->contact_id, 'conversation_id' => $lead->conversation_id, 'message_id' => $message, 'status' => 'opened'];
        });

        return [$response, $created];
    }

    private function readOpened(Request $http, TenantContext $tenant, string $leadId): array
    {
        return DB::transaction(function () use ($http, $tenant, $leadId): array {
            app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'leads');
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $lead = DB::table('seller_api_lead_requests')->where($scope)->where('id', $leadId)->where('status', 'opened')->lockForUpdate()->first();
            $message = $lead ? DB::table('messages')->where($scope)->where('conversation_id', $lead->conversation_id)
                ->where('role', 'agent')->where('meta->api_opening', $leadId)->value('id') : null;
            if (! $lead || ! is_string($message)) {
                throw new LogicException('API opening unavailable.');
            }

            return ['contact_id' => $lead->contact_id, 'conversation_id' => $lead->conversation_id, 'message_id' => $message, 'status' => 'opened'];
        });
    }
}
