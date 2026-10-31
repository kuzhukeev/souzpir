<?php

namespace App\Shell\Support\Contracts;

use Illuminate\Http\Request;

interface PlanRequestPort
{
    /** Outermost request boundary; rechecks session, tenant, owner and plan. */
    public function submit(Request $request, PlanRequestCommand $command): PlanRequestResult;
}
