<?php

namespace App\Providers\Features;

use Illuminate\Http\Request;

interface SharedPropsContributor
{
    /** Tenant-dependent values should be lazy closures resolved by Inertia. */
    public function share(Request $request): array;
}
