<?php

namespace App\Shell\Profile;

use App\Tenancy\ResolveTenant;
use Closure;
use Illuminate\Http\Request;

/** A personal-settings boundary: viewers may mutate themselves, never organization data. */
final class ProfileContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        return app(ResolveTenant::class)->handle($request, $next, personal: true);
    }
}
