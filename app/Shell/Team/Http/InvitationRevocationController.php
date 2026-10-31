<?php

namespace App\Shell\Team\Http;

use App\Shell\Team\InvitationRevocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InvitationRevocationController
{
    public function __invoke(Request $request, string $invitation, InvitationRevocation $action): RedirectResponse
    {
        $action->revoke($request, $invitation);

        return redirect()->route('team.index');
    }
}
