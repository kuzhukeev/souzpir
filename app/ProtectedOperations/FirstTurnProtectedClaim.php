<?php

namespace App\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Server-owned origin for a new first inbound turn, not authority to perform effects. */
final class FirstTurnProtectedClaim
{
    public function claim(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnCommand $command): TurnClaim
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() !== 0
                || ! Str::isUlid($command->conversationId) || ! Str::isUlid($command->inputMessageId)
                || ($command->externalEventId !== null && ! Str::isUlid($command->externalEventId))
                || $command->expectedVersion !== 0 || $command->idempotencyKey === '' || strlen($command->idempotencyKey) > 512) {
                throw new LogicException;
            }

            return app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $command): TurnClaim {
                [$tenant, $selection, $sessionHash] = $this->authorize($http, $user);
                $requestDigest = $this->requestDigest($tenant, $user, $selection, $sessionHash, $command);
                $receipt = app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                    'seller.first_turn_claim', $command->idempotencyKey, $requestDigest,
                    fn () => $this->create($tenant, $user, $selection, $sessionHash, $command, $requestDigest));
                if (! is_string($receipt['turn_id'] ?? null) || ! is_string($receipt['token'] ?? null)
                    || ! Str::isUlid($receipt['turn_id']) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['token']) !== 1) {
                    throw new LogicException;
                }
                $claim = new TurnClaim('claimed', $receipt['turn_id'], $command->conversationId, $tenant->organizationId, $tenant->brandId, 0, $receipt['token']);
                $turn = $this->verify($tenant, $user, $selection, $sessionHash, $claim, $requestDigest);
                if ($turn->status === 'uncertain') {
                    return new TurnClaim('uncertain', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, 0);
                }
                if (in_array($turn->status, ['completed', 'suggested', 'suppressed', 'failed'], true)) {
                    return new TurnClaim('finished', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, 0);
                }
                if ($turn->status !== 'processing') {
                    throw new LogicException;
                }

                return $claim;
            });
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Idempotency key was used with different content.');
        } catch (Throwable) {
            throw new LogicException('Protected first-turn claim unavailable.');
        }
    }

    /** Re-check under the caller's transaction; proof of origin alone never authorizes effects. */
    public function assertProtectedLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim): void
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $claim): void {
                [$tenant, $selection, $sessionHash] = $this->authorize($http, $user);
                app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
                $this->verify($tenant, $user, $selection, $sessionHash, $claim, null);
            });
        } catch (Throwable) {
            throw new LogicException('Protected first-turn claim unavailable.');
        }
    }

    private function authorize(#[SensitiveParameter] Request $http, User $user): array
    {
        $tenant = app(TenantContext::class);
        $selection = app(SessionBrandSelection::class)->current($http);
        $secret = config('app.key');
        if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail() || ! $tenant->membershipId
            || $selection->selected_organization_id !== $tenant->organizationId || $selection->selected_brand_id !== $tenant->brandId
            || ! is_string($secret) || $secret === '') {
            throw new LogicException;
        }
        ActiveTenantLock::acquire($tenant);
        $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
            ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
        $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('module', 'seller')->lockForUpdate()->first();
        $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)->orderBy('id')->lockForUpdate()->get(['plan_id']);
        $planIds = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
        if ($planIds !== []) {
            DB::table('plans')->whereIn('id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('plan_limits')->whereIn('plan_id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        if (! $member || ! $member->permitsBrand($tenant->brandId) || ! $module || ! $module->enabled
            || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
            throw new LogicException;
        }

        return [$tenant, $selection, hash_hmac('sha256', 'c02-protected-claim-session:'.$http->session()->getId(), $secret)];
    }

    private function requestDigest(TenantContext $tenant, User $user, object $selection, #[SensitiveParameter] string $sessionHash, #[SensitiveParameter] TurnCommand $command): string
    {
        $body = CanonicalJson::hash(['organization' => $tenant->organizationId, 'brand' => $tenant->brandId, 'actor' => $user->id,
            'session' => $sessionHash, 'selection_revision' => (string) $selection->brand_revision,
            'conversation' => $command->conversationId, 'input' => $command->inputMessageId, 'text' => $command->text,
            'event' => $command->externalEventId, 'expected_version' => $command->expectedVersion]);

        return hash_hmac('sha256', 'c02-protected-claim-request:'.$body, (string) config('app.key'));
    }

    private function create(TenantContext $tenant, User $user, object $selection, #[SensitiveParameter] string $sessionHash, #[SensitiveParameter] TurnCommand $command, string $requestDigest): array
    {
        $conversation = DB::table('conversations')->where('id', $command->conversationId)->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        $input = DB::table('messages')->where('id', $command->inputMessageId)->where('conversation_id', $command->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('role', 'client')->first();
        if (! $conversation || ! $input || (int) $conversation->version !== 0 || $conversation->history_ready !== true
            || (string) $conversation->history_revision !== '1' || (string) $conversation->history_sequence !== '1'
            || (string) $input->history_sequence !== '1' || ! hash_equals($input->text, $command->text)
            || $input->external_event_id !== $command->externalEventId || $conversation->stage !== 'open'
            || $conversation->client_type !== 'unknown' || $conversation->outcome !== null || $conversation->summary !== null
            || $conversation->mode !== 'bot' || $conversation->assigned_user_id !== null || $conversation->do_not_disturb
            || (int) $conversation->followups_sent !== 0 || $conversation->next_followup_at !== null
            || $conversation->last_client_message_at !== null || $conversation->last_bot_message_at !== null
            || $conversation->handed_off_at !== null || $conversation->taken_at !== null
            || json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR) !== []
            || DB::table('seller_turns')->where('conversation_id', $command->conversationId)->exists()) {
            throw new LogicException;
        }
        // No effect can be inferred from this marker: its only claim is that
        // this turn and its provenance were inserted in one transaction.
        app(KnowledgeRepository::class)->published($tenant);
        $claim = app(ConversationRepository::class)->claimTurn($tenant, $command);
        if ($claim->status !== 'claimed' || ! is_string($claim->token())) {
            throw new LogicException;
        }
        $data = ['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'actor_id' => $user->id,
            'session_hash' => $sessionHash, 'selection_revision' => (string) $selection->brand_revision,
            'request_digest' => $requestDigest, 'claim_hash' => hash('sha256', $claim->token())];
        $data['seal'] = $this->seal((object) $data);
        DB::table('c02_protected_turn_claims')->insert($data);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.protected_claimed', 'seller_turn', $claim->turnId));

        // The idempotency store encrypts this receipt; the raw token never enters the provenance row.
        return ['turn_id' => $claim->turnId, 'token' => $claim->token()];
    }

    private function verify(TenantContext $tenant, User $user, object $selection, #[SensitiveParameter] string $sessionHash, #[SensitiveParameter] TurnClaim $claim, ?string $requestDigest): object
    {
        if ($claim->status !== 'claimed' || $claim->organizationId !== $tenant->organizationId || $claim->brandId !== $tenant->brandId
            || $claim->baseVersion !== 0 || ! is_string($claim->token()) || preg_match('/\A[a-f0-9]{64}\z/D', $claim->token()) !== 1) {
            throw new LogicException;
        }
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->where('conversation_id', $claim->conversationId)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first();
        $row = DB::table('c02_protected_turn_claims')->where('turn_id', $claim->turnId)->lockForUpdate()->first();
        if (! $turn || ! $row || $row->organization_id !== $tenant->organizationId || $row->brand_id !== $tenant->brandId
            || $row->conversation_id !== $claim->conversationId || $row->actor_id !== $user->id
            || (string) $row->selection_revision !== (string) $selection->brand_revision
            || ! hash_equals($row->session_hash, $sessionHash) || ! hash_equals($row->seal, $this->seal($row))
            || ! hash_equals($row->claim_hash, hash('sha256', $claim->token())) || ! hash_equals($turn->claim_hash, $row->claim_hash)
            || ($requestDigest !== null && ! hash_equals($row->request_digest, $requestDigest))) {
            throw new LogicException;
        }

        return $turn;
    }

    private function seal(object $row): string
    {
        $data = ['id' => $row->id, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id,
            'conversation_id' => $row->conversation_id, 'turn_id' => $row->turn_id, 'actor_id' => $row->actor_id,
            'session_hash' => $row->session_hash, 'selection_revision' => (string) $row->selection_revision,
            'request_digest' => $row->request_digest, 'claim_hash' => $row->claim_hash];

        return hash_hmac('sha256', 'c02-protected-claim-seal:'.CanonicalJson::hash($data), (string) config('app.key'));
    }
}
