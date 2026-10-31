<?php

namespace App\Shell\Support;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Support\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SupportAccess
{
    public function actor(): Membership
    {
        $context = app(TenantContext::class);

        return Membership::query()->whereKey($context->membershipId)->where('user_id', $context->actorId)->where('status', 'active')->firstOrFail();
    }

    public function lock(): Membership
    {
        $context = app(TenantContext::class);
        abort_unless(DB::transactionLevel() > 0 && $context->source === 'session', 403);
        Organization::query()->whereKey($context->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $member = Membership::query()->whereKey($context->membershipId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $brand = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)->whereKey($context->brandId)->where('status', 'active')->sharedLock()->first();
        abort_unless($brand && $member->permitsBrand($brand->id), 403);

        return $member;
    }

    public function ticket(string $id, bool $lock = false): Ticket
    {
        $query = Ticket::query()->where('user_id', app(TenantContext::class)->actorId)->where('type', 'support')->whereKey($id);
        $ticket = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        Gate::authorize('view', $ticket);

        return $ticket;
    }
}
