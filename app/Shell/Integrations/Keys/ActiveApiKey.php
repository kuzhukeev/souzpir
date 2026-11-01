<?php

namespace App\Shell\Integrations\Keys;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class ActiveApiKey
{
    public static function matches(TenantContext $context, #[SensitiveParameter] string $value): bool
    {
        [$id, $token] = array_pad(explode('|', $value, 2), 2, null);
        if (! is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1
            || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) > 0)
            || ! is_string($token) || preg_match('/^[a-zA-Z0-9]{64}$/D', $token) !== 1) {
            return false;
        }

        return DB::table('personal_access_tokens')->where('id', $id)
            ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->where('tokenable_type', Brand::class)->where('tokenable_id', $context->brandId)
            ->where('token', hash('sha256', $token))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
