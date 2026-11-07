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

        // The proactive API path has a model call between two short database
        // transactions. Its controller revalidates the bearer before each write.
        $defaultAction = data_get($brand->settings, 'seller_api_action', 'card_only');
        $action = $request->input('action', $defaultAction);
        if ($request->routeIs('api.v1.leads.create')) {
            $request->attributes->set('seller.api.action', $action);
        }
        if ($request->routeIs('api.v1.leads.create') && $action === 'open') {
            [$currentToken, $context] = DB::transaction(function () use ($brand, $token): array {
                [, $currentToken, $context] = app(TokenTenantAuthority::class)->resolveLocked($brand, $token);
                DB::table('personal_access_tokens')->where('id', $currentToken->id)->update(['last_used_at' => now()]);

                return [$currentToken, $context];
            });
            $brand->withAccessToken($currentToken);
            $request->attributes->set('tenant.token', $currentToken);

            return app(TenantContextRunner::class)->run($context, fn () => $next($request));
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
