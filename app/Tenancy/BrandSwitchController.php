<?php

namespace App\Tenancy;

use App\Models\Membership;
use App\Shell\Auth\AuthSessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BrandSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['brand_id' => ['required', 'string', 'ulid']]);
        $context = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $data, $context): void {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $brand = DB::table('brands')->where('id', $data['brand_id'])->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->where('id', $context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless($member->permitsBrand($brand->id), 404);
            $request->session()->put('brand_id', $brand->id);
        });

        // Fixed same-origin destination; no user-supplied redirect URL.
        return redirect()->route('auth.ready');
    }
}
