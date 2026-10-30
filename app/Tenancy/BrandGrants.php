<?php

namespace App\Tenancy;

use App\Models\Brand;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BrandGrants
{
    public static function validate(string $organizationId, mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
            throw ValidationException::withMessages(['brand_ids' => __('Tenancy/Access/messages.invalid_brands')]);
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw ValidationException::withMessages(['brand_ids' => __('Tenancy/Access/messages.invalid_brands')]);
            }
        }
        if (Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['brand_ids' => __('Tenancy/Access/messages.invalid_brands')]);
        }

        return $ids;
    }
}
