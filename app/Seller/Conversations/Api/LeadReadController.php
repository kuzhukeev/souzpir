<?php

namespace App\Seller\Conversations\Api;

use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LeadReadController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeBrand($context);
        $input = $request->validate(['cursor' => ['sometimes', 'required', 'string', 'max:512']]);
        $cursor = null;
        $query = DB::table('contacts')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId);
        if (isset($input['cursor'])) {
            $cursor = Cursor::fromEncoded($input['cursor']);
            $data = $cursor?->toArray();
            if (! is_array($data) || count($data) !== 3 || ($data['_pointsToNextItems'] ?? null) !== true
                || ! is_string($data['id'] ?? null) || ! Str::isUlid($data['id'])
                || ! is_string($data['created_at'] ?? null)) {
                throw ValidationException::withMessages(['cursor' => 'Некорректный курсор страницы.']);
            }
            $anchor = (clone $query)->where('id', $data['id'])->first(['created_at']);
            if (! $anchor || ! hash_equals((string) $anchor->created_at, $data['created_at'])) {
                throw ValidationException::withMessages(['cursor' => 'Некорректный курсор страницы.']);
            }
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50, ['id', 'name', 'created_at'], 'cursor', $cursor);

        return response()->json(['data' => $page->items(), 'next_cursor' => $page->nextCursor()?->encode()])->header('Cache-Control', 'private, no-store');
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
