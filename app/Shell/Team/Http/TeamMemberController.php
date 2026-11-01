<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Team\TeamMemberProjection;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class TeamMemberController
{
    public function __invoke(Request $request, string $membership): Response
    {
        Gate::authorize('team.read');
        $member = Membership::query()->with('user:id,name,email,phone')
            ->findOrFail($membership, ['id', 'organization_id', 'user_id', 'role', 'status', 'brand_ids', 'settings']);
        Gate::authorize('view', $member);
        $data = TeamMemberProjection::forCurrentActor()->project($member);
        $data['phone'] = $member->user?->phone;

        $activityUrl = Gate::allows('journal.read') && Route::has('journal.index')
            ? route('journal.index', ['actor' => $member->user_id], false)
            : null;

        $actor = Membership::query()->findOrFail(app(TenantContext::class)->membershipId);
        $unrestricted = in_array($actor->role, ['owner', 'admin'], true) && $actor->brand_ids === [];
        $controlled = $unrestricted || ($member->brand_ids !== [] && array_diff($member->brand_ids, $actor->brand_ids) === []);
        $revision = app(AuthSessionRegistry::class)->withinActive($request, function () use ($request): string {
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            $ctx = app(TenantContext::class);
            abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected && $selection->selected_organization_id === $ctx->organizationId && $selection->selected_brand_id === $ctx->brandId, 409);

            return app(SessionBrandSelection::class)->profileRevision($request, $expected);
        });

        return Inertia::render('Shell/Team/Show', ['member' => $data, 'activityUrl' => $activityUrl,
            'editAccess' => Gate::allows('update', $member) && $controlled && $member->status === 'active'
                ? ['brands' => TeamMemberProjection::forCurrentActor()->availableBrands(), 'allBrands' => $unrestricted,
                    'memberAllBrands' => $member->role === 'admin' && $member->brand_ids === [], 'revision' => $revision] : null]);
    }
}
