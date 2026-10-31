<?php

namespace App\Shell\Team\Http;

use App\Models\Brand;
use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TeamMembersController
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('team.read');
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        abort_if($page === false, 404);

        $context = app(TenantContext::class);
        $actor = Membership::query()->whereKey($context->membershipId)->where('user_id', $context->actorId)->where('status', 'active')->firstOrFail();
        // Brand names are projected only from the actor's allowed active brands.
        $brands = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('status', 'active')
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])->filter(fn (Brand $brand) => $actor->permitsBrand($brand->id))->values();
        $members = Membership::query()->with('user:id,name,email')->orderBy('id')
            ->paginate(25, ['id', 'user_id', 'role', 'status', 'brand_ids', 'settings'], 'page', $page);
        $labels = config('permissions.labels', []);
        $items = $members->getCollection()->map(function (Membership $membership) use ($brands, $labels): array {
            $settings = $membership->settings;
            $manager = is_array($settings) ? ($settings['sales_manager'] ?? false) : null;
            $role = is_bool($manager) && (! $manager || $membership->role === 'member')
                ? match ($membership->role) {
                    'owner', 'admin', 'viewer' => $membership->role,
                    'member' => $manager ? 'manager' : 'editor',
                    default => null,
                } : null;
            $ids = $membership->brand_ids;
            $validIds = is_array($ids) && array_is_list($ids);
            $ids = $validIds ? $ids : [];
            $all = $validIds && in_array($membership->role, ['owner', 'admin'], true) && $ids === [];
            $shared = $brands->filter(fn (Brand $brand) => $all || in_array($brand->id, $ids, true))
                ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])->values()->all();

            return [
                'id' => $membership->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'role' => $role,
                'role_label' => is_array($labels) && $role !== null && is_string($labels[$role] ?? null) ? $labels[$role] : null,
                'status' => $membership->status,
                'brands' => $shared,
            ];
        })->all();

        return Inertia::render('Shell/Team/Index', [
            'members' => [
                'data' => $items,
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'total' => $members->total(),
            ],
            'canViewRoles' => Gate::allows('team.manage'),
        ]);
    }
}
