<?php

namespace App\ProtectedOperations;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Quarantine an unresolved turn; this does not establish or undo any effect. */
final class SellerTurnQuarantine
{
    public function quarantine(#[SensitiveParameter] Request $request, #[SensitiveParameter] string $turnId): array
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() !== 0 || ! Str::isUlid($turnId)) {
                throw new LogicException;
            }

            return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($turnId): array {
                if ($tenant->source !== 'session' || ! $user->hasVerifiedEmail()) {
                    throw new LogicException;
                }
                $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('module', 'seller')->lockForUpdate()->first();
                $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)
                    ->orderBy('id')->lockForUpdate()->get(['plan_id']);
                $planIds = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
                if ($planIds !== []) {
                    DB::table('plans')->whereIn('id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
                    DB::table('plan_limits')->whereIn('plan_id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
                }
                if (! $module || ! $module->enabled || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller')
                    || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                    throw new LogicException;
                }
                $query = DB::table('seller_turns')->where('id', $turnId)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
                $candidate = (clone $query)->first(['conversation_id']);
                if (! $candidate || ! DB::table('conversations')->where('id', $candidate->conversation_id)
                    ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->lockForUpdate()->first(['id'])) {
                    throw new LogicException;
                }
                $turn = $query->where('conversation_id', $candidate->conversation_id)->lockForUpdate()->first();
                if (! $turn || ! in_array($turn->status, ['processing', 'uncertain'], true)) {
                    throw new LogicException;
                }
                if ($turn->status === 'processing') {
                    DB::table('seller_turns')->where('id', $turnId)->update(['status' => 'uncertain', 'updated_at' => now()]);
                    app(AuditWriter::class)->record($tenant, new AuditEntry('seller.turn.quarantined', 'seller_turn', $turnId,
                        before: ['status' => 'processing'], after: ['status' => 'uncertain']));
                }

                return ['status' => 'uncertain'];
            });
        } catch (Throwable) {
            throw new LogicException('Seller turn quarantine unavailable.');
        }
    }
}
