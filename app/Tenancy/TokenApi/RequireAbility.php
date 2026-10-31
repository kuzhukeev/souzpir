<?php

namespace App\Tenancy\TokenApi;

use Closure;
use Illuminate\Http\Request;

final class RequireAbility
{
    public function handle(Request $request, Closure $next, string $ability): mixed
    {
        $token = $request->attributes->get('tenant.token');
        abort_unless($token instanceof BrandAccessToken && in_array($ability, ['read', 'turn', 'leads'], true), 403);
        // Do not admit wildcard or unknown abilities from a malformed legacy record.
        abort_unless(is_array($token->abilities) && in_array($ability, $token->abilities, true), 403);

        return $next($request);
    }
}
