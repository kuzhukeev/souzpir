<?php

namespace App\Shell\Organizations\Middleware;

use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

final class RequireModule
{
    public function handle(Request $request, Closure $next, string $module): mixed
    {
        abort_unless(app(ModuleEntitlements::class)->enabled(app(TenantContext::class), $module), 404);

        return $next($request);
    }
}
