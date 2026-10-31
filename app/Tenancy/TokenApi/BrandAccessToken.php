<?php

namespace App\Tenancy\TokenApi;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken;

/** findToken accepts only the digest produced by BearerBoundary, never a raw bearer. */
final class BrandAccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public static function findToken(#[\SensitiveParameter] $token)
    {
        if (! app()->bound('request') || request()->attributes->get('token_api.boundary') !== true
            || ! is_string($token) || ! preg_match('/^([1-9][0-9]{0,18})\|([a-f0-9]{64})$/D', $token, $parts)) {
            return null;
        }
        $record = self::query()->whereKey($parts[1])->where('tokenable_type', Brand::class)
            ->whereColumn('tokenable_id', 'brand_id')->first();

        return $record && hash_equals($record->token, $parts[2]) ? $record : null;
    }

    public function tokenable(): MorphTo
    {
        // Lookup is reached only after constant-time verification against an owned token.
        return $this->morphTo('tokenable')->withoutGlobalScopes()
            ->where('id', $this->brand_id)->where('organization_id', $this->organization_id);
    }
}
