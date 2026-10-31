<?php

namespace App\Tenancy\TokenApi;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class BrandReadController
{
    public function __invoke(): JsonResponse
    {
        $context = app(TenantContext::class);
        $brand = Brand::query()->findOrFail($context->brandId);

        return response()->json(['id' => $brand->id, 'name' => $brand->name])
            ->header('Cache-Control', 'private, no-store');
    }
}
