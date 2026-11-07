<?php

namespace App\Tenancy\TokenApi;

use App\Models\Brand;
use App\Tenancy\TenantContextRunner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResolveTokenTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $brand = $request->user('sanctum');
        $token = $brand instanceof Brand ? $brand->currentAccessToken() : null;
        abort_unless($brand instanceof Brand && $token instanceof BrandAccessToken, 401);
        if ($request->hasHeader('X-Brand-Id')) {
            abort_unless($request->header('X-Brand-Id') === $brand->id, 404);
        }

        // Laravel's routing pipeline renders downstream exceptions into responses before
        // this middleware regains control. Force a rollback for those responses too.
        $rollback = new RuntimeException('Token API transaction rolled back.');
        $response = null;
        try {
            return DB::transaction(function () use ($request, $next, $brand, $token, $rollback, &$response) {
                [, $currentToken, $context] = app(TokenTenantAuthority::class)->resolveLocked($brand, $token);
                DB::table('personal_access_tokens')->where('id', $currentToken->id)->update(['last_used_at' => now()]);
                $brand->withAccessToken($currentToken);
                $request->attributes->set('tenant.token', $currentToken);
                $response = app(TenantContextRunner::class)->run($context, fn () => $next($request));
                if ($response->getStatusCode() >= 400 || isset($response->exception)) {
                    throw $rollback;
                }

                return $response;
            });
        } catch (RuntimeException $exception) {
            if ($exception !== $rollback) {
                throw $exception;
            }

            return $response;
        }
    }
}
