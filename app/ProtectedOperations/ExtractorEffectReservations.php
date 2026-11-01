<?php

namespace App\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Ai\Protected\SellerExtractorStages;
use App\Seller\Conversations\Data\TurnClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Immutable evidence of one protected extractor reservation, not an effect outcome. */
final class ExtractorEffectReservations
{
    /** Integrity check only; the caller must separately authorize the stage. */
    public function assertBoundIfPresentLocked(#[SensitiveParameter] object $stage): void
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            $row = DB::table('c02_extractor_effect_reservations')->where('extractor_stage_id', $stage->id)->lockForUpdate()->first();
            if ($row && ($stage->ai_call_id === null || $stage->gateway_request_hash === null
                || $row->organization_id !== $stage->organization_id || $row->brand_id !== $stage->brand_id
                || $row->conversation_id !== $stage->conversation_id || $row->turn_id !== $stage->turn_id
                || $row->ai_call_id !== $stage->ai_call_id || ! hash_equals($row->request_hash, $stage->gateway_request_hash)
                || ! hash_equals($row->seal, $this->seal($row)))) {
                throw new LogicException;
            }
        } catch (Throwable) {
            throw new LogicException('Protected extractor reservation evidence unavailable.');
        }
    }

    public function recordNewLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $callId, #[SensitiveParameter] string $requestHash): void
    {
        try {
            [$stage, $call] = $this->sources($http, $claim, $stageId, $callId, $requestHash);
            if (DB::table('c02_extractor_effect_reservations')->where('extractor_stage_id', $stageId)->exists()) {
                throw new LogicException;
            }
            $data = ['id' => (string) Str::ulid(), 'organization_id' => $stage->organization_id, 'brand_id' => $stage->brand_id,
                'conversation_id' => $stage->conversation_id, 'turn_id' => $stage->turn_id, 'extractor_stage_id' => $stage->id,
                'ai_call_id' => $call->id, 'request_hash' => $requestHash];
            $data['seal'] = $this->seal((object) $data);
            DB::table('c02_extractor_effect_reservations')->insert($data);
        } catch (Throwable) {
            throw new LogicException('Protected extractor reservation evidence unavailable.');
        }
    }

    private function sources(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $callId, #[SensitiveParameter] string $requestHash): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() < 1
            || ! Str::isUlid($stageId) || ! Str::isUlid($callId) || preg_match('/\A[a-f0-9]{64}\z/D', $requestHash) !== 1
            || ! is_string(config('app.key')) || config('app.key') === '') {
            throw new LogicException;
        }
        // This verifies session, tenant, permissions, claim provenance and
        // freshness again; supplied identifiers are not authority.
        app(SellerExtractorStages::class)->load($http, $claim, $stageId);
        $stage = DB::table('c02_extractor_stages')->where('id', $stageId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
        $call = DB::table('ai_calls')->where('id', $callId)->where('organization_id', $claim->organizationId)
            ->where('brand_id', $claim->brandId)->lockForUpdate()->first();
        if (! $stage || ! $call || $stage->ai_call_id !== $callId || $stage->conversation_id !== $claim->conversationId
            || $stage->organization_id !== $claim->organizationId || $stage->brand_id !== $claim->brandId
            || ! is_string($stage->gateway_request_hash) || ! hash_equals($stage->gateway_request_hash, $requestHash)
            || ! hash_equals($call->request_hash, $requestHash) || $call->object_type !== 'conversation'
            || $call->object_id !== $claim->conversationId || $call->family !== 'seller' || $call->purpose !== 'extractor') {
            throw new LogicException;
        }

        return [$stage, $call];
    }

    private function seal(#[SensitiveParameter] object $row): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'extractor_stage_id', 'ai_call_id', 'request_hash'] as $field) {
            $identity[$field] = (string) $row->{$field};
        }

        return hash_hmac('sha256', 'c02-extractor-effect-reservation:'.CanonicalJson::hash($identity), (string) config('app.key'));
    }
}
