<?php

namespace App\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        abort_unless($user, 401);
        $organizationId = app(Cabinet::class)->organizationId();
        $organization = Organization::query()->whereKey($organizationId)->where('status', 'active')->first();
        abort_unless($organization, 403);
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
        abort_unless($membership, 403);
        $brands = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (Brand $brand) => $membership->permitsBrand($brand->id))->values();
        $selected = $request->session()->get('brand_id');
        $brand = $brands->firstWhere('id', $selected);
        // A stale tab must never write into a replacement brand after access changes.
        if ($brand === null && ! $request->isMethodSafe() && ! $request->routeIs('brand.switch')) {
            abort(404);
        }
        $brand ??= $brands->first();
        abort_unless($brand, 403);
        // An optional header never selects an organization or bypasses the session scope.
        if ($request->hasHeader('X-Brand-Id')) {
            abort_unless($request->header('X-Brand-Id') === $brand->id, 404);
        }
        if (! $request->isMethodSafe() && ! $request->routeIs('brand.switch')) {
            abort_unless($membership->canWrite(), 403);
        }
        $request->session()->put('brand_id', $brand->id);
        $request->attributes->set('tenant.organization', $organization);
        $request->attributes->set('tenant.brand', $brand);
        $request->attributes->set('tenant.brands', $brands);
        $request->attributes->set('tenant.membership', $membership);
        $context = new TenantContext($organizationId, $brand->id, $user->id, $membership->id, 'session');

        return app(TenantContextRunner::class)->run($context, fn () => $next($request));
    }
}
