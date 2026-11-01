<?php

namespace App\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Ai\Gateway;
use App\Ai\Protected\SellerExtractorStages;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** A narrow first-turn state source; it does not authorize effects or mutate conversation state. */
final class FirstTurnState
{
    public function prepare(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId): string
    {
        try {
            return DB::transaction(function () use ($http, $claim, $stageId): string {
                [$stage, $json] = $this->derive($http, $claim, $stageId);
                $existing = DB::table('c02_first_turn_states')->where('turn_id', $claim->turnId)->lockForUpdate()->first();
                if ($existing) {
                    $this->verify($existing, $stage, $json);

                    return $existing->id;
                }
                $data = ['id' => (string) Str::ulid(), 'organization_id' => $claim->organizationId, 'brand_id' => $claim->brandId,
                    'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'extractor_stage_id' => $stage->id,
                    'ai_call_id' => $stage->ai_call_id, 'release_id' => $stage->release_id,
                    'state_encrypted' => Crypt::encryptString($json), 'state_digest' => $this->digest($json)];
                $data['seal'] = $this->seal((object) $data);
                DB::table('c02_first_turn_states')->insert($data);

                return $data['id'];
            });
        } catch (Throwable) {
            throw new LogicException('Protected first-turn state unavailable.');
        }
    }

    /** Read only while the caller's transaction holds all authorization and freshness locks. */
    public function readLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $stateId): array
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            [$stage, $json] = $this->derive($http, $claim, $stageId);
            $row = DB::table('c02_first_turn_states')->where('id', $stateId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
            if (! $row) {
                throw new LogicException;
            }
            $this->verify($row, $stage, $json);

            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new LogicException('Protected first-turn state unavailable.');
        }
    }

    private function derive(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException;
        }
        // This verifies the actual session, rights, current turn, active release,
        // request binding and the persisted Gateway result under the same locks.
        $extraction = app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stageId);
        [, $context, , $callId, , , $message] = app(SellerExtractorStages::class)->load($http, $claim, $stageId);
        if (! is_string($callId) || ! is_string($message) || ($extraction['_meta']['model'] ?? null) === 'fallback') {
            throw new LogicException;
        }
        $stage = DB::table('c02_extractor_stages')->where('id', $stageId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->lockForUpdate()->first();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->where('conversation_id', $claim->conversationId)->lockForUpdate()->first();
        if (! $stage || $stage->ai_call_id !== $callId || ! $conversation || ! $turn || $claim->baseVersion !== 0
            || (int) $conversation->version !== 0 || $conversation->history_ready !== true
            || (string) $conversation->history_revision !== '1' || (string) $conversation->history_sequence !== '1'
            || (string) $turn->base_history_revision !== '1' || (string) $turn->cutoff_sequence !== '1'
            || $conversation->stage !== 'open' || $conversation->client_type !== 'unknown' || $conversation->outcome !== null
            || $conversation->summary !== null || $conversation->mode !== 'bot' || $conversation->assigned_user_id !== null
            || $conversation->do_not_disturb || (int) $conversation->followups_sent !== 0
            || $conversation->next_followup_at !== null || $conversation->last_client_message_at !== null
            || $conversation->last_bot_message_at !== null || $conversation->handed_off_at !== null || $conversation->taken_at !== null
            || DB::table('seller_turns')->where('conversation_id', $claim->conversationId)->where('id', '<>', $claim->turnId)->exists()) {
            throw new LogicException;
        }
        $prior = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($prior) || $prior !== []) {
            throw new LogicException;
        }
        $tenant = app(TenantContext::class);
        $knowledge = app(KnowledgeRepository::class)->forRelease($tenant, $stage->release_id, false);
        if (! hash_equals($stage->release_checksum, $knowledge->checksum())) {
            throw new LogicException;
        }
        $state = new LeadState;
        $extraction['_text'] = mb_strtolower($message);
        (new Policy($knowledge))->applyExtraction($state, $extraction);

        return [$stage, json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)];
    }

    private function verify(#[SensitiveParameter] object $row, #[SensitiveParameter] object $stage, #[SensitiveParameter] string $expected): void
    {
        if ($row->organization_id !== $stage->organization_id || $row->brand_id !== $stage->brand_id
            || $row->conversation_id !== $stage->conversation_id || $row->turn_id !== $stage->turn_id
            || $row->extractor_stage_id !== $stage->id || $row->ai_call_id !== $stage->ai_call_id || $row->release_id !== $stage->release_id
            || ! hash_equals($this->seal($row), $row->seal)) {
            throw new LogicException;
        }
        $saved = Crypt::decryptString($row->state_encrypted);
        if (! hash_equals($row->state_digest, $this->digest($saved)) || ! hash_equals($row->state_digest, $this->digest($expected))) {
            throw new LogicException;
        }
    }

    private function digest(#[SensitiveParameter] string $json): string
    {
        return hash_hmac('sha256', 'c02-first-turn-state:'.$json, (string) config('app.key'));
    }

    private function seal(#[SensitiveParameter] object $row): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'extractor_stage_id', 'ai_call_id', 'release_id', 'state_digest'] as $field) {
            $identity[$field] = (string) $row->{$field};
        }

        return hash_hmac('sha256', 'c02-first-turn-state-seal:'.CanonicalJson::hash($identity), (string) config('app.key'));
    }
}
