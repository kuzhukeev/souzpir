<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Shell\Team\Models\Invitation;
use App\Shell\Team\TeamMemberProjection;
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
        $members = Membership::query()->with('user:id,name,email')->orderBy('id')
            ->paginate(25, ['id', 'user_id', 'role', 'status', 'brand_ids', 'settings'], 'page', $page);
        $items = $members->getCollection()->map(fn (Membership $membership) => $projection->project($membership))->all();
        $invitationQuery = Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
        $invitations = $projection->scopeInvitations($invitationQuery)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25, ['id', 'email', 'role', 'settings', 'brand_ids', 'expires_at', 'exchanged_at'], 'invitation_page', $invitationPage);
        $invitationItems = $invitations->getCollection()->map(function (Invitation $invitation) use ($projection): array {
            $grant = $projection->grant($invitation->role, $invitation->settings, $invitation->brand_ids);

            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_label' => $grant['role_label'],
                'brands' => $grant['brands'],
                'expires_at' => $invitation->expires_at->toISOString(),
                'opened' => $invitation->exchanged_at !== null,
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
            'invitations' => [
                'data' => $invitationItems,
                'current_page' => $invitations->currentPage(),
                'last_page' => $invitations->lastPage(),
                'total' => $invitations->total(),
            ],
        ]);
    }
}
