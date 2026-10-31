<?php

namespace App\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\Cabinet;
use App\Tenancy\ResolveTenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Closure;
use Illuminate\Http\Request;

/** A personal-settings boundary: viewers may mutate themselves, never organization data. */
final class ProfileContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethodSafe()) {
            return app(ResolveTenant::class)->handle($request, $next);
        }
        $user = $request->user();
        abort_unless($user, 401);
        $org = Organization::whereKey(app(Cabinet::class)->organizationId())->where('status', 'active')->first() ?? abort(403);
        $member = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $user->id)->where('status', 'active')->first() ?? abort(403);
        $brandId = $request->session()->get('brand_id');
        abort_unless(is_string($brandId), 404);
        $brand = Brand::withoutGlobalScopes()->whereKey($brandId)->where('organization_id', $org->id)->where('status', 'active')->first() ?? abort(404);
        abort_unless($member->permitsBrand($brand->id), 404);
        abort_if($request->hasHeader('X-Brand-Id') && $request->header('X-Brand-Id') !== $brand->id, 404);
        $request->attributes->set('tenant.organization', $org);
        $request->attributes->set('tenant.brand', $brand);
        $request->attributes->set('tenant.membership', $member);

        return app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $user->id, $member->id), fn () => $next($request));
    }
}
