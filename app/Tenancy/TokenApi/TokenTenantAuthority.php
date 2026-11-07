<?php

namespace App\Tenancy\TokenApi;

use App\Models\Brand;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Revalidates the original bearer token and tenant inside a short transaction. */
final class TokenTenantAuthority
{
    public function resolveLocked(Brand $brand, BrandAccessToken $token): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Token verification requires a transaction.');
        }
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

        return [$currentBrand, $currentToken, new TenantContext($organization->id, $currentBrand->id, source: 'token')];
    }

    public function revalidateLocked(Request $request, TenantContext $expected, string $ability): BrandAccessToken
    {
        $brand = $request->user('sanctum');
        $token = $request->attributes->get('tenant.token');
        abort_unless($brand instanceof Brand && $token instanceof BrandAccessToken && $expected->source === 'token', 401);
        [, $current, $tenant] = $this->resolveLocked($brand, $token);
        abort_unless($tenant->organizationId === $expected->organizationId && $tenant->brandId === $expected->brandId, 404);
        abort_unless(is_array($current->abilities) && in_array($ability, $current->abilities, true), 403);

        return $current;
    }
}
