<?php

namespace App\Http\Middleware;

use App\Data\Shared\AuthUserData;
use App\Http\PreviewProps;
use App\Providers\Features\FeatureRegistry;
use App\Shell\Dashboard\DashboardController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next)
    {
        // Inertia's factory may outlive a request in test runners and long-lived workers.
        // Never let cabinet/preview props bleed into an unrelated public response.
        Inertia::flushShared();
        try {
            return parent::handle($request, $next);
        } finally {
            Inertia::flushShared();
        }
    }

    public function share(Request $request): array
    {
        $registry = app(FeatureRegistry::class);
        $preview = app()->environment('local', 'testing') && $request->is('preview', 'preview/*', 'dev', 'dev/*');
        $contributions = $preview ? PreviewProps::make() : $registry->sharedProps($request);
        $permissions = $contributions['can'] ?? [];

        return [
            ...parent::share($request),
            'auth' => fn () => ['user' => ($user = $request->user()) ? (new AuthUserData(
                id: (string) $user->getAuthIdentifier(), name: $user->name, email: $user->email,
            ))->toArray() : null],
            'organization' => null,
            'brand' => null,
            'brandAppearance' => null,
            'brands' => [],
            // Null means that a real source has not been registered yet, not zero usage.
            'modules' => ['seller' => null, 'vision' => null],
            'unread' => fn () => $preview ? null : app(DashboardController::class)->unread($request),
            'visionProfileReady' => function () use ($request, $preview): bool {
                if ($preview || ! $request->user()) {
                    return false;
                }
                $org = $request->attributes->get('tenant.organization');
                $brand = $request->attributes->get('tenant.brand');
                $member = $request->attributes->get('tenant.membership');
                if (! $org || ! $brand || ! $member || $member->user_id !== $request->user()->id || $member->status !== 'active' || ! $member->permitsBrand($brand->id)) {
                    return false;
                }

                return DB::table('brand_profiles')->where('organization_id', $org->id)->where('brand_id', $brand->id)->exists();
            },
            'request_id' => (string) Str::uuid(),
            ...$contributions,
            // PHP's empty array would otherwise serialize as [] instead of a permission map.
            'can' => fn () => (object) (is_callable($permissions) ? $permissions() : $permissions),
            'theme' => function () use ($request): string {
                $theme = $request->attributes->get('tenant.brand')?->theme;

                return in_array($theme, ['confectionery', 'pyro', 'neutral'], true) ? $theme : 'neutral';
            },
            'translations' => (object) $registry->translations(),
            'ziggy' => fn () => [
                ...(new Ziggy)->filter(['generated::*'], false)->jsonSerialize(),
                'location' => ['host' => $request->getHttpHost(), 'pathname' => $request->getPathInfo(), 'search' => ''],
            ],
        ];
    }
}
