<?php

namespace App\Shell\Team\Http;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class RoleMatrixPageController
{
    public function __invoke(): Response
    {
        Gate::authorize('team.manage');

        return Inertia::render('Shell/Team/Roles');
    }
}
