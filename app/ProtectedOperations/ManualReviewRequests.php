<?php

namespace App\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
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

    private function readOrRequestInternal(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId, bool $create): array
    {
        if ($request->all() !== []) {
            abort(422);
        }
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() !== 0 || ! Str::isUlid($turnId)) {
            throw new LogicException;
        }

        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($request, $turnId, $create): array {
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

            return $this->readOrRequestLocked($request, $tenant, $turnId, $create);
        });
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
        if ($record->organization_id !== $tenant->organizationId || $record->brand_id !== $tenant->brandId
            || $record->conversation_id !== $turn->conversation_id || $record->turn_id !== $turnId
            || $record->reason !== 'outcome_unconfirmed' || ! is_string($record->seal)
            || ! hash_equals($this->seal($record, $secret), $record->seal)) {
            throw new LogicException;
        }

        return ['review' => 'requested', 'reason' => 'outcome_unconfirmed', 'other_effects_may_exist' => true];
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
