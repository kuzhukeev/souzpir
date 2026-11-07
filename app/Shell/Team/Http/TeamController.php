<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Shell\Team\InvitationDelivery;
use App\Shell\Team\TeamAccess;
use App\Shell\Team\TeamActions;
use App\Shell\Team\TeamQueries;
use App\Shell\Team\TeamRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController
{
    public function __construct(private TeamAccess $access, private TeamActions $actions, private TeamQueries $queries) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Membership::class);

        return Inertia::render('Shell/Team/Index', ['team' => $this->queries->index($request)]);
    }

    public function roles(): Response
    {
        Gate::authorize('team.read');

        return Inertia::render('Shell/Team/Roles', ['team' => [...TeamRoles::matrix(), 'can_manage' => Gate::allows('team.write')]]);
    }

    public function show(Request $request, string $membership): Response
    {
        $member = $this->access->member($membership);
        Gate::authorize('view', $member);

        return Inertia::render('Shell/Team/Show', ['team' => $this->queries->show($member, $request)]);
    }

    public function update(Request $request, string $membership): JsonResponse
    {
        $member = $this->access->member($membership);
        Gate::authorize('update', $member);
        $this->actions->update($request, $member, $request->validate($this->grantRules()));

        return response()->json(['data' => ['id' => $member->id, 'status' => 'updated']]);
    }

    public function disable(Request $request, string $membership): JsonResponse
    {
        $member = $this->access->member($membership);
        Gate::authorize('update', $member);
        $this->actions->disable($request, $member);

        return response()->json(['data' => ['id' => $member->id, 'status' => 'disabled']]);
    }

    public function invite(Request $request, InvitationDelivery $delivery): JsonResponse
    {
        Gate::authorize('create', Membership::class);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254'], ...$this->grantRules()]);

        return response()->json(['data' => $delivery->deliver($this->actions->invite($request, $data))], 201);
    }

    public function resend(Request $request, string $invitation, InvitationDelivery $delivery): JsonResponse
    {
        $subject = $this->access->invitation($invitation);
        Gate::authorize('team.write');

        return response()->json(['data' => $delivery->deliver($this->actions->resend($request, $subject))], 201);
    }

    public function revoke(Request $request, string $invitation): JsonResponse
    {
        $subject = $this->access->invitation($invitation);
        Gate::authorize('team.write');
        $this->actions->revoke($request, $subject);

        return response()->json(['data' => ['id' => $subject->id, 'status' => 'revoked']]);
    }

    private function grantRules(): array
    {
        return ['role' => ['required', Rule::in(TeamRoles::EDITABLE)], 'brand_ids' => ['present', 'array', 'max:1000'], 'brand_ids.*' => ['required', 'string', 'ulid', 'distinct']];
    }
}
