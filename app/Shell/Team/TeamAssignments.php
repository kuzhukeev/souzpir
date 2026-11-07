<?php

namespace App\Shell\Team;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Read-only bridge until the seller domain exposes an assignment-transfer contract. */
final class TeamAssignments
{
    private const TABLES = ['conversations', 'seller_tasks'];

    /** Called under TeamAccess's organization lock, shared by seller ActiveTenantLock. */
    public function assertCanPersist(Membership $proposed): void
    {
        if (DB::transactionLevel() === 0 || app(TenantContext::class)->organizationId !== $proposed->organization_id) {
            throw new LogicException('Assignment checks require the current organization transaction.');
        }
        $roles = config('permissions.abilities', [])['seller.dialogs.write'] ?? [];
        $canOperate = is_array($roles) && in_array(TeamRoles::name($proposed), $roles, true);
        if ($proposed->status !== 'active') {
            foreach (self::TABLES as $table) {
                if ($this->assigned($table, $proposed)->exists()) {
                    throw ValidationException::withMessages(['member' => __('Shell/Team/messages.assignments_disable')]);
                }
            }

            return;
        }
        if (! TeamRoles::allBrands($proposed)) {
            foreach (self::TABLES as $table) {
                if ($this->assigned($table, $proposed)->whereNotIn('brand_id', $proposed->brand_ids)->exists()) {
                    throw ValidationException::withMessages(['brand_ids' => __('Shell/Team/messages.assignments_grants')]);
                }
            }
        }
        if (! $canOperate) {
            foreach (self::TABLES as $table) {
                if ($this->assigned($table, $proposed)->exists()) {
                    throw ValidationException::withMessages(['role' => __('Shell/Team/messages.assignments_permission')]);
                }
            }
        }
    }

    public function currentBrand(Membership $member): array
    {
        $context = app(TenantContext::class);
        abort_unless($context->organizationId === $member->organization_id, 404);
        $available = $context->brandId !== null && Gate::allows('seller.dialogs.read');

        return [
            'available' => $available, 'brand_id' => $context->brandId,
            'conversations' => $available ? $this->assigned('conversations', $member)->where('brand_id', $context->brandId)->count() : null,
            'tasks' => $available ? $this->assigned('seller_tasks', $member)->where('brand_id', $context->brandId)->count() : null,
            'can_transfer' => false,
        ];
    }

    private function assigned(string $table, Membership $member): Builder
    {
        return DB::table($table)->where('organization_id', $member->organization_id)->where('assigned_user_id', $member->user_id);
    }
}
