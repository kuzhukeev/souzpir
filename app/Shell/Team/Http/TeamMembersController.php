<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
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
        abort_if($page === false, 404);

        $projection = TeamMemberProjection::forCurrentActor();
        $members = Membership::query()->with('user:id,name,email')->orderBy('id')
            ->paginate(25, ['id', 'user_id', 'role', 'status', 'brand_ids', 'settings'], 'page', $page);
        $items = $members->getCollection()->map(fn (Membership $membership) => $projection->project($membership))->all();

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
