<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Shell\Team\TeamMemberProjection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class TeamMemberController
{
    public function __invoke(string $membership): Response
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

        return Inertia::render('Shell/Team/Show', ['member' => $data, 'activityUrl' => $activityUrl]);
    }
}
