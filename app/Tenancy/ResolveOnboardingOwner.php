<?php

namespace App\Tenancy;

use App\Models\Membership;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;

/** Organization-only context exists solely on onboarding routes, never as a brand fallback. */
final class ResolveOnboardingOwner
{
    public function handle(Request $request, Closure $next): mixed
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasVerifiedEmail(), 403);
        // A client header must never select another tenant or a brand in this mode.
        abort_if($request->hasHeader('X-Brand-Id'), 404);
        $org = Organization::whereKey(app(Cabinet::class)->organizationIdFor($actor))->where('status', 'active')->firstOrFail();
        $member = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $actor->id)->where('status', 'active')->where('role', 'owner')->first();
        abort_unless($member && $org->owner_user_id === $actor->id, 403);
        $request->attributes->set('tenant.organization', $org);
        $request->attributes->set('tenant.membership', $member);
        $context = new TenantContext($org->id, null, $actor->id, $member->id, 'session');

        return app(TenantContextRunner::class)->run($context, fn () => $next($request));
    }
}
