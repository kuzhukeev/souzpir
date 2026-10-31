<?php

namespace App\Vision\Core\Profiles;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Transactional session, tenant, entitlement and ability boundary for private quiz data. */
final class ProfileAccess
{
    public function run(#[\SensitiveParameter] Request $request, Closure $action): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $action): mixed {
            $context = app(TenantContext::class);
            if ($context->source !== 'session' || ! $context->organizationId || ! $context->brandId
                || ! $context->membershipId || $context->actorId !== $user->id
                || $request->session()->get('brand_id') !== $context->brandId
                || ($request->hasHeader('X-Brand-Id') && $request->header('X-Brand-Id') !== $context->brandId)) {
                throw new LogicException('Vision profile access denied.');
            }
            $org = DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first();
            $brand = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first();
            $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $context->organizationId)
                ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $org || ! $brand || ! $member || ! $member->permitsBrand($context->brandId)
                || ! app(ModuleEntitlements::class)->enabled($context, 'vision') || ! Gate::forUser($user)->allows('vision.quiz.write')) {
                throw new LogicException('Vision profile access denied.');
            }

            return $action($context, $user);
        });
    }
}
