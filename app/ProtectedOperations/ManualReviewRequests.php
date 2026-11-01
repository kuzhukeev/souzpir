<?php

namespace App\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Records only a request for review; it never resolves a turn or authorizes an effect. */
final class ManualReviewRequests
{
    /** Every call, including a repeat, rechecks session and tenant authority. */
    public function readOrRequest(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId, bool $create): array
    {
        try {
            return $this->readOrRequestInternal($request, $turnId, $create);
        } catch (AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            throw new LogicException('Manual review unavailable.');
        }
    }

    /** An explicit operator request quarantines an unresolved turn and records review atomically. */
    public function quarantineAndRequest(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId): array
    {
        return $this->quarantineAndRequestInternal($request, $turnId, false);
    }

    /** Internal coordinator path; the original request can contain its command fields. */
    public function quarantineForCoordinator(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId): array
    {
        return $this->quarantineAndRequestInternal($request, $turnId, true);
    }

    private function quarantineAndRequestInternal(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId, bool $allowCommandInput): array
    {
        try {
            if (! Str::isUlid($turnId)) {
                throw new LogicException;
            }

            return $this->authorized($request, function (TenantContext $tenant) use ($request, $turnId): array {
                $query = DB::table('seller_turns')->where('id', $turnId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
                $candidate = (clone $query)->first(['conversation_id']);
                if (! $candidate || ! DB::table('conversations')->where('id', $candidate->conversation_id)
                    ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first(['id'])) {
                    abort(404);
                }
                $turn = $query->where('conversation_id', $candidate->conversation_id)->lockForUpdate()->first(['status']);
                if (! $turn || ! in_array($turn->status, ['processing', 'uncertain'], true)) {
                    abort(404);
                }
                if ($turn->status === 'processing') {
                    $query->update(['status' => 'uncertain', 'updated_at' => now()]);
                    app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.quarantined', 'seller_turn', $turnId,
                        before: ['status' => 'processing'], after: ['status' => 'uncertain']));
                }

                return $this->readOrRequestLocked($request, $tenant, $turnId, true);
            }, allowCommandInput: $allowCommandInput);
        } catch (AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            throw new LogicException('Manual review unavailable.');
        }
    }

    private function readOrRequestInternal(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId, bool $create): array
    {
        if (! Str::isUlid($turnId)) {
            throw new LogicException;
        }

        return $this->authorized($request, fn (TenantContext $tenant): array => $this->readOrRequestLocked($request, $tenant, $turnId, $create));
    }

    /** Returns at most 100 verified requests per page; cursors do not confer access. */
    public function listRequested(#[SensitiveParameter] Request $request): array
    {
        try {
            return $this->authorized($request, fn (TenantContext $tenant): array => $this->listRequestedLocked($tenant, $request->input('cursor')), allowCursor: true);
        } catch (AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            throw new LogicException('Manual review unavailable.');
        }
    }

    private function authorized(#[SensitiveParameter] Request $request, Closure $action, bool $allowCursor = false, bool $allowCommandInput = false): array
    {
        $input = $request->all();
        if ((! $allowCommandInput && ! $allowCursor && $input !== []) || ($allowCursor && (array_diff(array_keys($input), ['cursor']) !== []
            || (array_key_exists('cursor', $input) && ! is_string($input['cursor']))))) {
            abort(422);
        }
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() !== 0) {
            throw new LogicException;
        }

        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($action): array {
            if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail()) {
                abort(403);
            }
            $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('module', 'seller')->lockForUpdate()->first();
            $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)->orderBy('id')->lockForUpdate()->get(['plan_id']);
            $planIds = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
            if ($planIds !== []) {
                DB::table('plans')->whereIn('id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
                DB::table('plan_limits')->whereIn('plan_id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            if (! $module || ! $module->enabled || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller')
                || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                abort(403);
            }

            return $action($tenant);
        });
    }

    private function listRequestedLocked(#[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] ?string $cursor): array
    {
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new LogicException;
        }
        $boundary = null;
        if ($cursor !== null) {
            if (strlen($cursor) !== 91) {
                abort(422);
            }
            $parts = explode('.', $cursor);
            if (count($parts) !== 2 || ! Str::isUlid($parts[0]) || ! preg_match('/^[0-9a-f]{64}$/D', $parts[1])
                || ! hash_equals($this->cursorSignature($tenant, $parts[0], $secret), $parts[1])) {
                abort(422);
            }
            $boundary = $parts[0];
            $boundaryRow = $this->reviewRows($tenant)->where('r.id', $boundary)->first();
            if (! $boundaryRow) {
                abort(422);
            }
            $this->verifyListRecord($boundaryRow, $tenant, $secret);
        }
        $query = $this->reviewRows($tenant);
        if ($boundary !== null) {
            $query->where('r.id', '<', $boundary);
        }
        $rows = $query->orderByDesc('r.id')->limit(101)->get();
        $items = [];
        foreach ($rows as $row) {
            $this->verifyListRecord($row, $tenant, $secret);
            if (count($items) < 100) {
                $items[] = ['conversation_id' => $row->conversation_id, 'turn_id' => $row->turn_id,
                    'reason' => $row->reason, 'other_effects_may_exist' => true];
            }
        }

        $result = ['data' => $items, 'limit' => 100, 'has_more' => $rows->count() > 100];
        if ($result['has_more']) {
            $id = $rows[99]->id;
            $result['next_cursor'] = $id.'.'.$this->cursorSignature($tenant, $id, $secret);
        }

        return $result;
    }

    private function reviewRows(#[SensitiveParameter] TenantContext $tenant): Builder
    {
        return DB::table('c02_manual_review_requests as r')
            ->leftJoin('seller_turns as t', 't.id', '=', 'r.turn_id')
            ->where('r.organization_id', $tenant->organizationId)->where('r.brand_id', $tenant->brandId)
            ->select(['r.*', 't.organization_id as turn_organization_id',
                't.brand_id as turn_brand_id', 't.conversation_id as turn_conversation_id']);
    }

    private function verifyListRecord(#[SensitiveParameter] object $row, #[SensitiveParameter] TenantContext $tenant, string $secret): void
    {
        if ($row->turn_organization_id !== $tenant->organizationId || $row->turn_brand_id !== $tenant->brandId
            || $row->turn_conversation_id !== $row->conversation_id) {
            throw new LogicException;
        }
        $this->verifyRecord($row, $tenant, $row->conversation_id, $row->turn_id, $secret);
    }

    private function cursorSignature(#[SensitiveParameter] TenantContext $tenant, string $id, string $secret): string
    {
        return hash_hmac('sha256', 'c02-review-page:'.$tenant->organizationId.':'.$tenant->brandId.':'.$id, $secret);
    }

    private function readOrRequestLocked(#[SensitiveParameter] Request $request, #[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] string $turnId, bool $create): array
    {
        $secret = config('app.key');
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() < 1
            || $tenant->source !== 'session' || ! $tenant->organizationId || ! $tenant->brandId || ! $tenant->actorId
            || ! $request->hasSession() || ! Str::isUlid($turnId) || ! is_string($secret) || $secret === '') {
            throw new LogicException;
        }
        $query = DB::table('seller_turns')->where('id', $turnId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $candidate = (clone $query)->first(['conversation_id']);
        if (! $candidate || ! DB::table('conversations')->where('id', $candidate->conversation_id)
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first(['id'])) {
            abort(404);
        }
        $turn = $query->where('conversation_id', $candidate->conversation_id)->where('status', 'uncertain')->lockForUpdate()->first(['id', 'conversation_id']);
        if (! $turn) {
            abort(404);
        }
        $record = DB::table('c02_manual_review_requests')->where('turn_id', $turnId)->lockForUpdate()->first();
        if (! $record && $create) {
            $data = ['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'conversation_id' => $turn->conversation_id, 'turn_id' => $turnId, 'actor_id' => $tenant->actorId,
                'session_hash' => hash_hmac('sha256', 'c02-review-session:'.$request->session()->getId(), $secret),
                'reason' => 'outcome_unconfirmed'];
            $data['seal'] = $this->seal((object) $data, $secret);
            DB::table('c02_manual_review_requests')->insert([...$data, 'created_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.review_requested', 'seller_turn', $turnId,
                after: ['reason' => 'outcome_unconfirmed']));
            $record = (object) $data;
        }
        if (! $record) {
            return ['review' => 'not_requested', 'other_effects_may_exist' => true];
        }
        $this->verifyRecord($record, $tenant, $turn->conversation_id, $turnId, $secret);

        return ['review' => 'requested', 'reason' => 'outcome_unconfirmed', 'other_effects_may_exist' => true];
    }

    private function verifyRecord(#[SensitiveParameter] object $record, #[SensitiveParameter] TenantContext $tenant, string $conversationId, string $turnId, string $secret): void
    {
        if ($record->organization_id !== $tenant->organizationId || $record->brand_id !== $tenant->brandId
            || $record->conversation_id !== $conversationId || $record->turn_id !== $turnId
            || $record->reason !== 'outcome_unconfirmed' || ! is_string($record->seal)
            || ! hash_equals($this->seal($record, $secret), $record->seal)) {
            throw new LogicException;
        }
    }

    private function seal(#[SensitiveParameter] object $record, #[SensitiveParameter] string $secret): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'actor_id', 'session_hash', 'reason'] as $field) {
            if (! is_string($record->{$field} ?? null)) {
                throw new LogicException;
            }
            $identity[$field] = $record->{$field};
        }

        return hash_hmac('sha256', 'c02-manual-review-request:'.CanonicalJson::hash($identity), $secret);
    }
}
