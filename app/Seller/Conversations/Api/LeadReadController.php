<?php

namespace App\Seller\Conversations\Api;

use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class LeadReadController
{
    public function index(TenantContext $context): JsonResponse
    {
        $this->authorizeBrand($context);
        $rows = DB::table('contacts')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)->get(['id', 'name', 'created_at']);

        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function show(TenantContext $context, string $lead): JsonResponse
    {
        $this->authorizeBrand($context);
        $row = DB::table('contacts')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('id', $lead)
            ->first(['id', 'name', 'created_at']) ?? abort(404);

        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeBrand(TenantContext $context): void
    {
        abort_unless($context->organizationId && $context->brandId
            && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
    }
}
