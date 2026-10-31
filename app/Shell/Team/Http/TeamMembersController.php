<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Team\Models\Invitation;
use App\Shell\Team\TeamMemberProjection;
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
        $invitationPage = filter_var($request->query('invitation_page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        abort_if($page === false || $invitationPage === false, 404);

        $projection = TeamMemberProjection::forCurrentActor();
        $canWrite = Gate::allows('team.write');
        $members = Membership::query()->with('user:id,name,email')->orderBy('id')
            ->paginate(25, ['id', 'user_id', 'role', 'status', 'brand_ids', 'settings'], 'page', $page);
        $items = $members->getCollection()->map(fn (Membership $membership) => $projection->project($membership))->all();
        $invitationQuery = Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
        $invitations = $projection->scopeInvitations($invitationQuery)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25, ['id', 'email', 'role', 'settings', 'brand_ids', 'expires_at', 'exchanged_at'], 'invitation_page', $invitationPage);
        $invitationItems = $invitations->getCollection()->map(function (Invitation $invitation) use ($projection, $canWrite): array {
            $grant = $projection->grant($invitation->role, $invitation->settings, $invitation->brand_ids);

            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_label' => $grant['role_label'],
                'brands' => $grant['brands'],
                'expires_at' => $invitation->expires_at->toISOString(),
                'opened' => $invitation->exchanged_at !== null,
                'can_revoke' => $projection->canRevokeInvitation($invitation->brand_ids, $canWrite),
            ];
        })->all();
        $context = app(TenantContext::class);
        $brandRevision = app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $context): string {
            $expected = $request->attributes->get('tenant.brand_revision');
            $selections = app(SessionBrandSelection::class);
            $selection = $selections->current($request);
            abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected
                && $selection->selected_organization_id === $context->organizationId
                && $selection->selected_brand_id === $context->brandId, 409);

            return $selections->profileRevision($request, $expected);
        });

        return Inertia::render('Shell/Team/Index', [
            'members' => [
                'data' => $items,
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'total' => $members->total(),
            ],
            'canViewRoles' => Gate::allows('team.manage'),
            'teamBrandRevision' => $brandRevision,
            'invitations' => [
                'data' => $invitationItems,
                'current_page' => $invitations->currentPage(),
                'last_page' => $invitations->lastPage(),
                'total' => $invitations->total(),
            ],
        ]);
    }
}
