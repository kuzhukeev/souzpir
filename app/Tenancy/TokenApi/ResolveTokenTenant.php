<?php

namespace App\Tenancy\TokenApi;

use App\Models\Brand;
use App\Models\Organization;
use App\Tenancy\TenantContext;
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
                $organization = Organization::whereKey($token->organization_id)->where('status', 'active')->lockForUpdate()->first();
                abort_unless($organization, 404);
                $currentBrand = Brand::withoutGlobalScopes()->whereKey($token->brand_id)
                    ->where('organization_id', $organization->id)->where('status', 'active')->lockForUpdate()->first();
                abort_unless($currentBrand && $currentBrand->id === $brand->id, 404);
                $currentToken = BrandAccessToken::query()->whereKey($token->id)->where('organization_id', $organization->id)
                    ->where('brand_id', $currentBrand->id)->where('tokenable_type', Brand::class)
                    ->where('tokenable_id', $currentBrand->id)->lockForUpdate()->first();
                abort_unless($currentToken && hash_equals($currentToken->token, $token->token)
                    && (! $currentToken->expires_at || $currentToken->expires_at->isFuture()), 401);
                DB::table('personal_access_tokens')->where('id', $currentToken->id)->update(['last_used_at' => now()]);
                $brand->withAccessToken($currentToken);
                $request->attributes->set('tenant.token', $currentToken);
                $context = new TenantContext($organization->id, $currentBrand->id, source: 'token');

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
