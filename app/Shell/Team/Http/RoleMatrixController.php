<?php

namespace App\Shell\Team\Http;

use App\Shell\Team\RoleMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class RoleMatrixController
{
    public function __invoke(RoleMatrix $matrix): JsonResponse
    {
        Gate::authorize('team.manage');

        return response()->json($matrix->project());
    }
}
