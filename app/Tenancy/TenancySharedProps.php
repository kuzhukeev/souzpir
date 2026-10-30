<?php

namespace App\Tenancy;

use App\Providers\Features\SharedPropsContributor;
use Illuminate\Http\Request;

final class TenancySharedProps implements SharedPropsContributor
{
    public function share(Request $request): array
    {
        // Middleware share() runs before ResolveTenant; values resolve inside its context.
        return [
            'organization' => fn () => ($organization = $request->attributes->get('tenant.organization'))
                ? ['id' => $organization->id, 'name' => $organization->name] : null,
            'brand' => fn () => ($brand = $request->attributes->get('tenant.brand'))
                ? ['id' => $brand->id, 'name' => $brand->name, 'theme' => $brand->theme] : null,
            'brands' => fn () => $request->attributes->get('tenant.brands')?->map(fn ($brand) => ['id' => $brand->id, 'name' => $brand->name])->all() ?? [],
        ];
    }
}
