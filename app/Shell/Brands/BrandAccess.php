<?php

namespace App\Shell\Brands;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class BrandAccess
{
    public function read(Request $request, string $id, string $ability = 'brand.write'): Brand
    {
        $ctx = app(TenantContext::class);
        $member = $request->attributes->get('tenant.membership');
        abort_unless($member instanceof Membership && Str::isUlid($id) && $member->permitsBrand($id), 404);
        $brand = Brand::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->whereKey($id)->firstOrFail();
        Gate::authorize($ability);

        return $brand;
    }

    public function context(string $id): TenantContext
    {
        $ctx = app(TenantContext::class);

        return new TenantContext($ctx->organizationId, $id, $ctx->actorId, $ctx->membershipId, $ctx->source);
    }

    public function change(Request $request, string $id, Closure $action): mixed
    {
        $this->read($request, $id);
        $ctx = app(TenantContext::class);

        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $id, $action) {
            Organization::whereKey($ctx->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $member = Membership::withoutGlobalScopes()->whereKey($ctx->membershipId)->where('organization_id', $ctx->organizationId)->where('user_id', $ctx->actorId)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->lockForUpdate()->first();
            abort_unless($member && $ctx->brandId && $member->permitsBrand($ctx->brandId) && DB::table('brands')->where('id', $ctx->brandId)->where('organization_id', $ctx->organizationId)->where('status', 'active')->exists(), 403);
            abort_unless($member->permitsBrand($id), 404);
            $target = Brand::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->whereKey($id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $scoped = $this->context($target->id);

            return app(TenantContextRunner::class)->run($scoped, fn () => $action($target, $scoped));
        });
    }
}
