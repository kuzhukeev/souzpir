<?php

namespace App\Shell\Organizations;

use App\Providers\Features\SharedPropsContributor;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ModuleSharedProps implements SharedPropsContributor
{
    public function share(Request $request): array
    {
        return ['modules' => function () use ($request): array {
            $context = app(TenantContext::class);
            $brand = $context->brandId;
            // Onboarding is an owner-only org context; verify the finished brand and its membership explicitly.
            if (! $brand && $request->routeIs('onboarding.show') && $context->organizationId && $context->membershipId && $context->actorId) {
                $member = $request->attributes->get('tenant.membership');
                $candidate = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->whereNotNull('completed_at')->value('brand_id');
                if ($candidate && $member && $member->id === $context->membershipId && $member->user_id === $context->actorId && $member->permitsBrand($candidate)) {
                    $brand = $candidate;
                }
            }
            if (! $brand || ! $context->organizationId) {
                return ['seller' => null, 'vision' => null];
            }
            $scoped = new TenantContext($context->organizationId, $brand, $context->actorId, $context->membershipId, $context->source);
            $service = app(ModuleEntitlements::class);

            return ['seller' => $service->enabled($scoped, 'seller'), 'vision' => $service->enabled($scoped, 'vision')];
        }];
    }
}
