<?php

namespace App\Shell\Billing;

use App\Models\Organization;
use App\Shell\Support\SupportAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BillingAccess
{
    public function __construct(private SupportAccess $access) {}

    /** Recheck the active tenant, membership and selected brand inside the session transaction. */
    public function lock(bool $manage): Organization
    {
        $actor = $this->access->lock();
        $tenant = app(TenantContext::class);
        $org = Organization::query()->whereKey($tenant->organizationId)->where('status', 'active')->firstOrFail();
        if ($manage) {
            Gate::authorize('billing.manage');
            abort_unless($actor->role === 'owner' && $org->owner_user_id === $actor->user_id, 403);
        } else {
            Gate::authorize('billing.read');
        }

        return $org;
    }

    /** Bind sensitive payer operations to the context captured by the originating page. */
    public function lockPayer(Request $request): Organization
    {
        $org = $this->lock(true);
        $scope = SubmissionScope::for(app(TenantContext::class));
        $expected = $request->header('X-Billing-Context');
        abort_unless($scope !== null && is_string($expected) && hash_equals($scope, $expected), 409);

        return $org;
    }
}
