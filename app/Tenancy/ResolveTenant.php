<?php

namespace App\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\EnforceAuthVersion;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next, bool $personal = false): mixed
    {
        $user = $request->user();
        abort_unless($user, 401);
        $authVersion = app(EnforceAuthVersion::class);
        $authVersion->ensure($request);
        $organizationId = app(Cabinet::class)->organizationIdFor($user);
        $organization = Organization::query()->whereKey($organizationId)->where('status', 'active')->first();
        abort_unless($organization, 403);
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->where('user_id', $user->id)->where('status', 'active')->first();
        abort_unless($membership, 403);
        $brands = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (Brand $brand) => $membership->permitsBrand($brand->id))->values();
        $snapshot = $request->session()->get('brand_id');
        // An unsafe request may never initialize or recover a missing/revoked
        // selection to another brand, including for personal settings.
        if (! $request->isMethodSafe() && ! $request->routeIs('brand.switch')
            && (! is_string($snapshot) || ! $brands->firstWhere('id', $snapshot))) {
            abort(404);
        }
        $initial = $brands->firstWhere('id', $snapshot) ?? $brands->first();
        abort_unless($initial, 403);

        try {
            [$selection, $recovered] = app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $organizationId, &$organization, &$membership, &$brands, &$initial): array {
                $selections = app(SessionBrandSelection::class);
                $selection = $selections->initialize($request, $organizationId, $initial->id);
                abort_unless($selection->selected_organization_id === $organizationId, 403);

                if (! $request->isMethod('GET') || $brands->firstWhere('id', $selection->selected_brand_id)) {
                    return [$selection, false];
                }

                // The previous selection is no longer usable. Serialize recovery with
                // archival and membership edits and recheck all permissions under lock.
                // Never move a mutating request to a different brand implicitly.
                $organization = Organization::query()->whereKey($organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
                $lockedBrands = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->where('status', 'active')
                    ->orderBy('id')->lockForUpdate()->get();
                $membership = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->where('user_id', $request->user()->id)
                    ->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
                $brands = $lockedBrands->filter(fn (Brand $brand) => $membership->permitsBrand($brand->id))
                    ->sortBy([['name', 'asc'], ['id', 'asc']])->values();
                $initial = $brands->first() ?? abort(403);
                if ($brands->firstWhere('id', $selection->selected_brand_id)) {
                    return [$selection, false];
                }
                if ($request->hasHeader('X-Brand-Id')) {
                    abort_unless($request->header('X-Brand-Id') === $initial->id, 404);
                }
                $selections->select($request, $organizationId, $initial->id, (string) $selection->brand_revision);

                return [$selections->current($request), true];
            });
        } catch (AuthenticationException $error) {
            // A concurrent revocation after ensure() must clear this guard too.
            $authVersion->invalidate($request);
            throw $error;
        }
        abort_unless($selection->selected_organization_id === $organizationId, 403);
        $brand = $brands->firstWhere('id', $selection->selected_brand_id);
        if (! $brand && $request->routeIs('brand.switch')) {
            $brand = $initial;
        }
        abort_unless($brand, 404);
        if ($personal && ! $recovered && is_string($snapshot) && $snapshot !== $brand->id) {
            $request->session()->put('brand_id', $brand->id);
            abort(409);
        }
        if (! $request->isMethodSafe() && ! $request->routeIs('brand.switch') && is_string($snapshot) && $snapshot !== $brand->id) {
            abort(409);
        }
        if ($request->hasHeader('X-Brand-Id')) {
            abort_unless($request->header('X-Brand-Id') === $brand->id, 404);
        }
        $ownSettings = $request->isMethod('PATCH') && $request->routeIs('notifications.settings.update', 'data.consents');
        $ownSupportActions = $request->isMethod('POST') && $request->routeIs('help.support.store', 'help.support.access.revoke');
        if (! $personal && ! $request->isMethodSafe() && ! $request->routeIs('brand.switch') && ! $ownSettings && ! $ownSupportActions) {
            abort_unless($membership->canWrite(), 403);
        }
        $request->session()->put('brand_id', $brand->id);
        $request->attributes->set('tenant.organization', $organization);
        $request->attributes->set('tenant.brand', $brand);
        $request->attributes->set('tenant.brands', $brands);
        $request->attributes->set('tenant.membership', $membership);
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $context = new TenantContext($organizationId, $brand->id, $user->id, $membership->id, 'session');

        return app(TenantContextRunner::class)->run($context, function () use ($context, $next, $request) {
            $module = $request->is('seller', 'seller/*') ? 'seller' : ($request->is('vision', 'vision/*') ? 'vision' : null);
            if ($module !== null) {
                abort_unless(app(ModuleEntitlements::class)->enabled($context, $module), 404);
            }

            return $next($request);
        });
    }
}
