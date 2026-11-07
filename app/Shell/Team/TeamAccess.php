<?php

namespace App\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\BrandGrants;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TeamAccess
{
    public function actor(): Membership
    {
        $ctx = app(TenantContext::class);

        return Membership::query()->whereKey($ctx->membershipId)->where('user_id', $ctx->actorId)->where('status', 'active')->firstOrFail();
    }

    /** Inside the active session transaction, after any target-user NOWAIT lock. */
    public function lock(): Membership
    {
        $ctx = app(TenantContext::class);
        abort_unless(DB::transactionLevel() > 0 && $ctx->source === 'session', 403);
        Organization::query()->whereKey($ctx->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $actor = Membership::query()->whereKey($ctx->membershipId)->where('user_id', $ctx->actorId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $brand = Brand::withoutGlobalScopes()->where('id', $ctx->brandId)->where('organization_id', $ctx->organizationId)->where('status', 'active')->sharedLock()->first();
        abort_unless($brand && $actor->permitsBrand($brand->id), 403);
        Gate::authorize('team.write');

        return $actor;
    }

    public function brands(Membership $actor): array
    {
        return Brand::withoutGlobalScopes()->where('organization_id', $actor->organization_id)->where('status', 'active')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (Brand $brand) => $actor->permitsBrand($brand->id))->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])->values()->all();
    }

    public function visible(object $subject, Membership $actor): bool
    {
        return TeamRoles::allBrands($actor) || TeamRoles::allBrands($subject) || array_intersect($subject->brand_ids, $actor->brand_ids) !== [];
    }

    public function controls(object $subject, Membership $actor): bool
    {
        return TeamRoles::allBrands($actor) || (! TeamRoles::allBrands($subject) && array_diff($subject->brand_ids, $actor->brand_ids) === []);
    }

    public function member(string $id, bool $lock = false, ?Membership $actor = null): Membership
    {
        $query = Membership::query()->whereKey($id);
        $member = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($this->visible($member, $actor ?? $this->actor()), 404);

        return $member;
    }

    public function invitation(string $id, bool $lock = false, ?Membership $actor = null): Invitation
    {
        $query = Invitation::query()->whereKey($id);
        $invitation = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($this->visible($invitation, $actor ?? $this->actor()), 404);

        return $invitation;
    }

    public function grants(Membership $actor, string $role, array $brandIds): array
    {
        $grant = TeamRoles::grant($role);
        $ids = BrandGrants::validate($actor->organization_id, $brandIds);
        if (($ids === [] && $role !== 'admin') || ($ids === [] && ! TeamRoles::allBrands($actor))
            || (! TeamRoles::allBrands($actor) && array_diff($ids, $actor->brand_ids) !== [])
            || Brand::withoutGlobalScopes()->where('organization_id', $actor->organization_id)->whereIn('id', $ids)->where('status', 'active')->count() !== count($ids)) {
            throw ValidationException::withMessages(['brand_ids' => __('Shell/Team/messages.invalid_brands')]);
        }
        sort($ids, SORT_STRING);

        return [...$grant, 'brand_ids' => $ids];
    }
}
