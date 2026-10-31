<?php

namespace App\Shell\Profile;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProfileAccess
{
    public function run(Request $request, Closure $action): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $action): mixed {
            $context = app(TenantContext::class);
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            if (is_string($selection->selected_brand_id) && $selection->selected_organization_id === $context->organizationId) {
                $request->session()->put('brand_id', $selection->selected_brand_id);
            }
            abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected
                && $selection->selected_organization_id === $context->organizationId
                && $selection->selected_brand_id === $context->brandId, 409);
            $profileRevision = app(SessionBrandSelection::class)->profileRevision($request, $expected);
            $request->attributes->set('profile.brand_revision', $profileRevision);
            if ($request->routeIs('profile.update')) {
                abort_unless($request->hasHeader('X-Brand-Revision'), 409);
            }
            if ($request->hasHeader('X-Brand-Revision')) {
                $provided = $request->header('X-Brand-Revision');
                abort_unless(is_string($provided) && hash_equals($profileRevision, $provided), 409);
            }
            abort_unless($context->actorId === $user->id && $context->organizationId && $context->brandId, 403);
            $org = DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $brand = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $org->id)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $org->id)->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless($member->permitsBrand($brand->id), 403);

            return $action($user, $context, $brand, $member, $org);
        });
    }
}
