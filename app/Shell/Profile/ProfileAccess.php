<?php

namespace App\Shell\Profile;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProfileAccess
{
    public function run(Request $request, Closure $action): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($action): mixed {
            $context = app(TenantContext::class);
            abort_unless($context->actorId === $user->id && $context->organizationId && $context->brandId, 403);
            $org = DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $brand = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $org->id)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $org->id)->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless($member->permitsBrand($brand->id), 403);

            return $action($user, $context, $brand, $member, $org);
        });
    }
}
